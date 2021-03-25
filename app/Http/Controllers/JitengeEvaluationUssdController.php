<?php

namespace App\Http\Controllers;

use App\Http\Requests\UssdRequest;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class JitengeEvaluationUssdController extends Controller
{
    //const END_POINT = "http://127.0.0.1:3000/api/";
    const END_POINT = "http://ears-api.mhealthkenya.co.ke/api/";
    private $client;
    private $sessionOpeningTag = "CON EARS - Jitenge.\n";
    private $sessionClosingTag = "END EARS - Jitenge.\n";

    public function __construct()
    {
        ini_set('max_execution_time', 0); //never times out

        $this->client = new Client();
    }

    public function handleRequest(UssdRequest $request)
    {
        $sessionId = request("sessionId");

        $phoneNumber = request("phoneNumber");

        $input = trim(request("text"));

        $session = $this->getSession($sessionId);

        if (empty($input)) {

            $response = $this->sessionOpeningTag . trans("ussd.choose_preferred_language");

        } else {

            $parts = array_filter(explode('*', $input));

            $language = null;

            if (isset($parts[0])) {

                $language = (int)$parts[0];

            }

            if ($language !== 1 && $language !== 2) {

                $response = $this->sessionOpeningTag . trans("ussd.invalid_preferred_language");

            } else {

                if (!isset($session['language'])) {

                    $session['sessionId'] = $sessionId;

                    $session['language'] = $language;

                    $this->setSession($session);

                }

                switch ($session['language']) {
                    case 2:

                        App::setLocale("sw_KE");

                        break;
                    default:

                        //App::setLocale($locale);

                        break;
                }

                //remove language selected
                unset($parts[0]);

                $parts = array_values($parts);

                $partsCount = count($parts);

                /**
                 * check if the user had tried to inject the phone number immediately after language to skip authentication
                 * if that's the case reset the array to empty and counter to zero to prompt for a phone number for authentication
                 */
                if (!isset($session['token']) && $partsCount > 1) {

                    $parts = [];

                    $partsCount = 0;

                }

                /**
                 * If the patient does not have a thermal gun we increase the array size by value of one and
                 * insert a null value to skip the question of capturing the body temperature
                 */
                if (isset($session["thermal_gun"]) && $session["thermal_gun"] == "NO") {

                    $partsCount += 1;

                    $bodyTemp = array(null);

                    array_splice($parts, 3, 0, $bodyTemp); // splice in at position 3

                    $session["body_temp"] = 0;

                    $this->setSession($session);

                }

                switch ($partsCount) {

                    case 0:

                        $response = $this->sessionOpeningTag . trans("ussd.enter_your_phone_number");

                        break;

                    case 1:

                        $isValidNumber = false;
                        $phoneNumberObject = null;
                        $phoneNumberUtil = PhoneNumberUtil::getInstance();
                        if ($phoneNumberUtil->isPossibleNumber($parts[0], "KE")) {
                            $phoneNumberObject = $phoneNumberUtil->parse($parts[0], "KE");
                            $isValidNumber = $phoneNumberUtil->isValidNumberForRegion($phoneNumberObject, "KE");
                        }

                        if (!$isValidNumber || $phoneNumberObject == null) {

                            $response = $this->sessionClosingTag . trans("ussd.invalid_phone_number");

                            $this->deleteSession($session);

                        } else {

                            $session['sessionId'] = $sessionId;

                            $session["phone_number"] = trimSpace($phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::INTERNATIONAL));

                            $response = $this->client->post(self::END_POINT . 'login', [
                                    'form_params' => [
                                        'phone_no' => trim(ltrim($session["phone_number"], "+")),
                                        'passport_no' => trim(ltrim($session["phone_number"], "+")),
                                    ],
                                    'cookies' => false
                                ]
                            );

                            $response = json_decode($response->getBody());

                            if (!empty($response) && $response->success === true) {

                                /* $language = 1;

                                 if (property_exists($response, 'language')) {

                                     $language = (int)$response->language;

                                 }

                                 $session['language'] = $language;*/

                                $session['is_hcw'] = (int)$response->is_hcw === 1;

                                $session['token'] = $response->message;

                                $session['client_id'] = $response->client_id;

                                $this->setSession($session);

                                if ($session['is_hcw']) {

                                    $response = $this->showPhoneNumberSearchInput($session);

                                } else {

                                    $response = $this->getListedPhoneNumbers($session);

                                }

                            } else {

                                $response = $this->sessionClosingTag . $response->message;

                            }

                        }

                        break;

                    case 2:

                        if ($session['is_hcw']) {

                            $response = $this->searchDriver($parts[1], $session);

                        } else {

                            $response = $this->performEvaluation($partsCount, $parts, $session);

                        }

                        break;

                    default:

                        if ($session['is_hcw']) {

                            unset($parts[1]);

                            $parts = array_values($parts);

                            $partsCount = count($parts);

                        }

                        $response = $this->performEvaluation($partsCount, $parts, $session);

                        break;

                }

            }

        }

        return response($response, 200)->header("Content-Type", "text/plain");
    }

    private function getSession($sessionId)
    {
        return Cache::get($sessionId);
    }

    private function setSession(array $session)
    {
        $sessionDurationMinutes = 15;

        Cache::put($session["sessionId"], $session, $sessionDurationMinutes);
    }

    private function deleteSession($session)
    {
        if (empty($session))
            return $session;

        if (!isset($session['sessionId']))
            return null;

        return Cache::pull($session["sessionId"]);
    }

    private function showPhoneNumberSearchInput($session)
    {
        return $this->sessionOpeningTag . trans("ussd.enter_phone_number_of_quarantine_case");
    }

    private function getListedPhoneNumbers($session)
    {
        $response = $this->client->post(self::END_POINT . 'contacts/attached', [
                'form_params' => [
                    'phone_no' => trim(ltrim($session["phone_number"], "+")),
                ],
                'headers' => ['Authorization' => 'Bearer ' . $session['token']],
                'cookies' => false
            ]
        );

        return $this->listClients($response, $session);
    }

    private function listClients($response, $session)
    {
        $response = json_decode($response->getBody());

        if (!empty($response) && $response->success === true) {

            if (count($response->clients) < 1) {

                $_response = $this->sessionClosingTag . trans("ussd.case_not_found");

                $this->deleteSession($session);

            } else {

                $session['clients'] = $response->clients;

                $this->setSession($session);

                $_response = $this->sessionOpeningTag . trans("ussd.select_quarantine_case");

                foreach ($response->clients as $index => $patient) {

                    $_response .= "\n" . ($index + 1) . ". $patient->first_name $patient->last_name";

                }

            }

            $response = $_response;

        } else {

            $response = $this->sessionClosingTag . $response->message;

        }

        return $response;
    }

    private function searchDriver($searchKey, $session)
    {
        $response = $this->client->post(self::END_POINT . 'search/client', [
                'form_params' => [
                    'phone_no' => trim(ltrim($searchKey, "+")),
                ],
                'headers' => ['Authorization' => 'Bearer ' . $session['token']],
                'cookies' => false
            ]
        );

        return $this->listClients($response, $session);

    }

    private function performEvaluation($arraySize, array $parts, $session)
    {
        switch ($arraySize) {

            case 2:

                $choiceIndex = ((int)$parts[1]) - 1;

                if (isset($session['clients']) && isset($session['clients'][$choiceIndex])) {

                    $session["client_id"] = $session['clients'][$choiceIndex]->id;

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . trans("ussd.do_you_have_a_thermometer");

                } else {

                    $response = $this->sessionClosingTag . trans("ussd.invalid_response");

                    $this->deleteSession($session);
                }

                break;

            case 3:

                if ($parts[2] == "1" || $parts[2] == "2") {

                    $session["thermal_gun"] = ($parts[2] == "1" ? "YES" : "NO");

                    $this->setSession($session);

                    if ($parts[2] == "2") {

                        $response = $this->sessionOpeningTag . trans("ussd.have_you_developed_fever");

                    } else {

                        $response = $this->sessionOpeningTag . trans("ussd.what_your_body_temperature");

                    }

                } else {

                    $response = $this->sessionClosingTag . trans("ussd.invalid_response");

                    $this->deleteSession($session);
                }

                break;

            case 4:

                $session["body_temp"] = (double)$parts[3];

                if ($session["body_temp"] >= 34 && $session["body_temp"] < 45) {

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . trans("ussd.have_you_developed_fever");

                } else {

                    $response = $this->sessionClosingTag . trans("ussd.invalid_body_temp");

                    $this->deleteSession($session);

                }

                break;

            case 5:

                if ($parts[4] == "1" || $parts[4] == "2") {

                    $session["fever"] = $parts[4] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . trans("ussd.have_you_developed_cough");

                } else {

                    $response = $this->sessionClosingTag . trans("ussd.invalid_response");

                    $this->deleteSession($session);
                }

                break;

            case 6:

                if ($parts[5] == "1" || $parts[5] == "2") {

                    $session["cough"] = $parts[5] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . trans("ussd.difficulty_breathing");

                } else {

                    $response = $this->sessionClosingTag . trans("ussd.invalid_response");

                    $this->deleteSession($session);
                }

                break;

            case 7:

                if ($parts[6] == "1" || $parts[6] == "2") {

                    $session["difficult_breathing"] = $parts[6] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . trans("ussd.provide_additional_comments");

                } else {

                    $response = $this->sessionClosingTag . trans("ussd.invalid_response");

                    $this->deleteSession($session);
                }

                break;

            case 8:

                $session["comment"] = $parts[7];

                $this->deleteSession($session);

                unset($session['sessionId']);

                unset($session['country']);

                unset($session['phone_number']);

                unset($session['clients']);

                $response = $this->client->post(self::END_POINT . 'response', [
                        'form_params' => $session,
                        'headers' => ['Authorization' => 'Bearer ' . $session['token']],
                        'cookies' => false
                    ]
                );

                $response = json_decode($response->getBody());

                $response = $this->sessionClosingTag . $response->message;

                break;

        }

        return $response;
    }
}

