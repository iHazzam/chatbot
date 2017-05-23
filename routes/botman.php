<?php
use App\Http\Controllers\BotManController;
// Don't use the Facade in here to support the RTM API too :)
$botman = resolve('botman');

$botman->hears('whoami', function($bot){
    $user = $bot->getUser();
    $bot->reply($user->getId());
});
$botman->hears('fishcake',BotManController::class.'@startConversation');
$botman->hears('(?i)(hello|hi|hey|howdy|hola|bonjour|good morning|good afternoon|good day)', BotManController::class.'@mainConversation');