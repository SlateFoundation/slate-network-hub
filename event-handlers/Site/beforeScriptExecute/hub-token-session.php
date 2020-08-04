<?php

use Firebase\JWT\JWT;

use Slate\NetworkHub\School;
use Slate\NetworkHub\User;

$hubToken = $_REQUEST['hub_token'];

if (!empty($hubToken)) {
    list($header, $payload, $signature) = explode('.', $hubToken);
    $decodedPayload = json_decode(base64_decode($payload), true);

    if (!empty($decodedPayload['hostname'])) {
        $NetworkSchool = School::getByField('Domain', $decodedPayload['hostname']);

        if ($NetworkSchool) {
            try {
                JWT::decode($hubToken, $NetworkSchool->APIKey, ['HS256']);
            } catch (Exception $e) {
                return RequestHandler::throwInvalidRequestError('hub_token is invalid. Please retry the request or contact an administrator if the issue persists.');
            }

            $NetworkUser = User::getByUsername($decodedPayload['user']['PrimaryEmail']);

            if (!$NetworkUser || $NetworkUser->SchoolID !== $NetworkSchool->ID) {
                return RequestHandler::throwInvalidRequestError('hub_token is invalid. Please retry the request or contact an administrator if the issue persists.');
            }

            $_SESSION['hub_token_payload'] = $decodedPayload;
            $GLOBALS['Session'] = $GLOBALS['Session']->changeClass('UserSession', [
                'PersonID' => $NetworkUser->ID
            ]);
        }
    }
}
