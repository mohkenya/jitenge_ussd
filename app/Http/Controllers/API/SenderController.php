<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller as Controller;
use App\Send_Log;
use App\Services\AfricasTalkingGateway;

class SenderController extends Controller
{

    //

    public function sender($outbox_id, $from, $recipients, $message)
    {
//        $from = $request->sender;
//        $recipients = $request->phone_no;
//        $message = $request->msg;
        //Sending Messages using sender id/short code

        $username = "mhealthkenya";
        $apikey = "9318d173cb9841f09c73bdd117b3c7ce3e6d1fd559d3ca5f547ff2608b6f3212";
        $gateway = new AfricasTalkingGateway($username, $apikey);


        $results = $gateway->sendMessage($recipients, $message, $from);

        foreach ($results as $result) {
            //echo " Number: " . $result->number;
            //echo " Status: " . $result->status;
            //echo " StatusCode: " . $result->statusCode;
            //echo " MessageId: " . $result->messageId;
            //echo " Cost: " . $result->cost . "\n";

            $number = $result->number;
            $status = $result->status;
            $statusCode = $result->statusCode;
            $messageId = $result->messageId;
            $cost = $result->cost;

            $Send_Log = new Send_Log;
            $Send_Log->number = $number;
            $Send_Log->status = $status;
            $Send_Log->statusCode = $statusCode;
            $Send_Log->messageId = $messageId;
            $Send_Log->cost = $cost;
            $Send_Log->save();
        }
        return $outbox_id;
    }

}
