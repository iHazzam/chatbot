<?php

namespace App\Http\Controllers;

use App\Conversations\ExampleConversation;
use Illuminate\Http\Request;
use Mpociot\BotMan\BotMan;
use Illuminate\Support\Facades\Log;

class BotManController extends Controller
{
    /**
     * Place your BotMan logic here.
     */
    public function handle()
    {
        Log::info('was called');
        $botman = app('botman');
        $d = $botman->verifyServices(env('TOKEN_VERIFY'));
        // Simple respond method
        Log::info('was verified');
        $botman->listen();
    }

    /**
     * Loaded through routes/botman.php
     * @param  BotMan $bot
     */
    public function startConversation(BotMan $bot)
    {
        $bot->startConversation(new ExampleConversation());
    }
    public function mainConversation(BotMan $bot)
    {
        $bot->startConversation(new MainConversation());
    }
}
