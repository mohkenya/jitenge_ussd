<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('jitenge-ussd', 'App\Http\Controllers\JitengeEvaluationUssdController@handleRequest');
Route::group([
    'namespace' => 'API',
    'middleware' => 'api',
], function () {
    Route::get('resources/special', 'ResourcesController@get_special_resources');
    Route::get('resources/special/{id}', 'ResourcesController@get_special_resource');
});
