<?php

namespace App\Conversations;

use App\Order;
use App\OrderProduct;
use GuzzleHttp\Client;
use Illuminate\Foundation\Inspiring;
use Mpociot\BotMan\Answer;
use Mpociot\BotMan\Button;
use Mpociot\BotMan\Conversation;
use Mpociot\BotMan\Facebook\ReceiptAddress;
use Mpociot\BotMan\Facebook\ReceiptElement;
use Mpociot\BotMan\Facebook\ReceiptSummary;
use Mpociot\BotMan\Facebook\ReceiptTemplate;
use Mpociot\BotMan\Question;
use Psy\Exception\ErrorException;
use Illuminate\Support\Facades\DB;
class MainConversation extends Conversation
{
    /**
     * First question
     */
    public function askPurpose()
    {
        $q1 = Question::create("What would you like to do with the system?")
            ->fallback('Unable to ask question')
            ->callbackId('ask_purpose')
            ->addButtons([
                Button::create('Place a new order')->value('new'),
                Button::create('See the last order you placed')->value('last'),
            ]);
        $this->ask($q1, function(Answer $a1){
            if($a1->isInteractiveMessageReply())
            {
                return $a1->getValue();
            }
            else return false;
        });
    }
    public function checkAuthStatus($uid)
    {
        $client = new Client();
        try{
            return $client->get('https://playdale.me/api/auth/'.$uid);
        }
        catch(ErrorException $e)
        {
            $this->say('Argh! I had a horrible error trying to authenticate. Sorry, please try again later!');
        }
    }
    private function getImageUrl($productcode)
    {
        $turn = DB::table('product_images')->where('code','=',$productcode)->first();
        if($turn == null)
        {
            return asset('storage/default.png');
        }
        else{
            return asset('storage/images'.$turn->path);
        }
    }
    public function returnSummary($uid)
    {
        $client = new Client();
        try{
           $last = json_decode($client->get('https://playdale.me/api/last/'.$uid)->getBody()->getContents());
           $lastorder = json_decode($client->get('https://playdale.me/api/last/order/'.$uid)->getBody()->getContents());
            $rt = ReceiptTemplate::create()
                ->recipientName($last->contact_name)
                ->merchantName('Playdale')
                ->orderNumber($last->id)
                ->orderUrl('http://playdale.app/admin/orders')
                ->currency($last->currency)
                ->addAddress(ReceiptAddress::create()
                    ->street1($last->address_line1)
                    ->street2($last->address_line2)
                    ->city($last->city)
                    ->postalCode($last->postcode)
                    ->country($last->country)
                )
                ->addSummary(ReceiptSummary::create()
                    ->subtotal($last->order_total)
                    ->shippingCost($last->shipping_total)
                    ->totalCost($last->shipping_total + $last->order_total)
                );
            foreach ($lastorder as $item)
            {
                $imageurl = $this->getImageUrl($item->product_code);
                $rt->addElement(ReceiptElement::create($item->product_code)->quantity($item->quantity)->price($item->price)->image($imageurl));
            }
            $this->bot->reply(
                $rt
            );
        }
        catch(ErrorException $e)
        {
            $this->say('Argh! I had a horrible error trying to authenticate. Sorry, please try again later!');
        }

    }
    public function getOrderName()
    {
        $this->ask('What would you like the order to be called?', function (Answer $response) {
            return $response->getText();
        });
    }
    public function getReference()
    {
        $this->ask('What is your order reference number?', function (Answer $response) {
            return $response->getText();
        });
    }
    public function askForProduct()
    {
        //Ask them to enter product code

        //Show them picture + price

        //Ask them to enter quantity

    }
    public function anyCustom()
    {
        $question = Question::create('Do you have any custom order details to enter?')
            ->fallback('Custom details are unavailable.')
            ->callbackId('custom_order')
            ->addButtons([
                Button::create('Yes, enter custom order')->value('yes'),
                Button::create('No, proceed without custom details!')->value('no'),
            ]);

        $this->say('Hey, so sometimes we do custom orders. These are usually pre-discussed and we will have given you some details');
        $this->ask($question, function (Answer $answer) {
            // Detect if button was clicked:
            if ($answer->isInteractiveMessageReply()) {
                if($answer->getValue()=='yes') // will be either 'yes' or 'no'
                {
                    $this->ask('Please enter now (in one message) your custom order request', function (Answer $response) {
                        return $response->getText();
                    });
                }
                else return null;
            }
            else return null;
        });
    }
    public function deliveryType()
    {

        $question = Question::create('Please select your desired delivery method!')
            ->fallback('Delivery not available')
            ->callbackId('delivery_method')
            ->addButtons([
                Button::create('Delivery')->value('delivery'),
                Button::create('Collection/Own Courier')->value('collection'),
                Button::create('Decide Later')->value('unconfirmed'),
            ]);
    }
    public function createNewOrder()
    {
        $order = new Order();
        $this->say('Great! Thanks for starting a new order!');
        $this->say('Can you tell me what the name of your order is?');
        //New order name
        $order->project_name = $this->getOrderName();//TODO:
        //PO reference
        $this->say('Now, please enter a reference for this order.');
        $order->purchase_order_reference = $this->getReference();//TODO:
        //Ask them to enter product code
        $this->say('Great, we\'re up and running!');
        $entering = true;
        while($entering == true)
        {
            //Ask them to enter another code, add custom details or finish order (loop)
            $entering = $this->askForProduct();//TODO:
        }

        //Any custom details
        $order->custom = $this->anyCustom();//TODO: return null or string
        
        //Select delivery type and show price
        $order->delivery = $this->deliveryType();//TODO: enum delivery', 'collection','unconfirmed'
        //Show them their last/default delivery address, change?
        if($order->delivery == "delivery")
        {
            $this->say('Now, let\'s check your delivery address is up to date. I think it is:');
            $this->sayAddress();//TODO:G
            $this->askIfAddressIsCorrect();//TODO:
        }
        //any last details
        $order->incoterms = $this->askAnyLastDetails();//TODO: string

        //show final invoice
        $this->showFinalInvoice(); //TODO:
        //confirm
        $this->confirm(); //TODO:
    }

    /**
     * Start the conversation
     */
    public function run()
    {
        $user = $this->bot->getUser();
        //Check Auth Status
        $as = $this->checkAuthStatus($user->getId());
        if(!$as)
        {
            $this->say('Hello '.$user->getFirstName().' '.$user->getLastName());
            $this->say("I'm sorry, but my powers are reserved only for authorised users of Playdale's EOS system trial. If you think you should be authorised, please visit EOS to link your account, or contact Playdale for more details");
            $this->say("https://playdale.me/user/settings");
        }
        else
        {
            $this->say('Hello '.$user->getFirstName().' '.$user->getLastName());
            $this->say('I am PlayBot, PEOS\'s facebook companion! Let\'s get started!');
            $purpose = $this->askPurpose();
            if($purpose === 'last')
            {
                $this->say('This is a summary of your last placed order!');
                $this->returnSummary();
                $this->say('It was fun talking! Bye!');
            } 
            elseif($purpose ==='new')
            {
                $this->createNewOrder();
                $this->say('Thanks for using my powers! Please come back soon! Bye!');
            }
            else{
                $this->say('Sorry, you didn\'t answer my question using the correct buttons. Please try again soon! Bye!');
            }

        }

    }
}
