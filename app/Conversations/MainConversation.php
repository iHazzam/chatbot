<?php

namespace App\Conversations;

use Illuminate\Foundation\Inspiring;
use Mpociot\BotMan\Facebook\ElementButton;
use App\Order;
use App\OrderProduct;
use App\Product;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
use Mpociot\BotMan\Facebook\GenericTemplate;
use Mpociot\BotMan\Facebook\Element;
use Illuminate\Support\Facades\Log;
class MainConversation extends Conversation
{
    /**
     * First question
     */
    protected $user;
    protected $ops = [];
    protected $order;
    protected $entering = true;
    protected $uid;
    public function askPurpose()
    {
        $question = Question::create("What would you like to do with the system?")
            ->fallback('Unable to ask question')
            ->callbackId('ask_purpose')
            ->addButtons([
                Button::create('Place a new order')->value('new'),
                Button::create('See the last order you placed')->value('last'),
            ]);

        return $this->ask($question, function (Answer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                //$answer->getValue()
                if($answer->getValue() === 'last')
                {
                    $this->say('This is a summary of your last placed order!');
                    $this->returnSummary();
                }
                elseif($answer->getValue() ==='new')
                {
                    $this->createNewOrder();
                }
                else{
                    $this->say('Sorry, you didn\'t answer my question using the correct buttons. Please try again soon! Bye!');
                }
            }
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
            return false;
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
    public function returnSummary()
    {
        $client = new Client();
        try{
           $last = json_decode($client->get('https://playdale.me/api/last/'.$this->uid)->getBody()->getContents());
           $lastorder = json_decode($client->get('https://playdale.me/api/last/order/'.$this->uid)->getBody()->getContents());
            $rt = ReceiptTemplate::create()
                ->recipientName($last->contact_name)
                ->merchantName('Playdale')
                ->orderNumber($last->id)
                ->orderUrl('http://playdale.me/admin/orders')
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
            $this->say('It was fun talking! Bye!');
        }
        catch(ErrorException $e)
        {
            $this->say('Argh! I had a horrible error trying to authenticate. Sorry, please try again later!');
        }

    }
    public function getOrderName()
    {
        $this->ask('What would you like the order to be called?', function (Answer $response) {
            $this->order->project_name = $response->getText();
            $this->say('Gotcha! What\'s next...');
            $this->getReference();
        });
    }
    public function getReference()
    {
        $this->ask('What is your order reference number?', function (Answer $response) {
            $this->order->purchase_order_reference = $response->getText();
            $this->order->save();
            $this->say('Great, we\'re up and running!');
            $entering = true;
            $this->say('Please now enter the product code you want to order');
            $this->say('I will then show you the price and ask you to confirm the addition to the order. Good luck!');
            $this->askForProduct();


        });
    }
    public function askForProduct()
    {
        $this->ask('Please tell me the product code of your item', function (Answer $response) {
            $producode = strtoupper($response);
            try {
                $product = Product::where("code", "=", $producode)->firstOrFail();
                $this->bot->userStorage()->save([
                    'product' => $product
                ]);
                $quest = GenericTemplate::create()
                    ->addImageAspectRatio(GenericTemplate::RATIO_SQUARE)
                    ->addElements([
                        Element::create($product->code)
                            ->subtitle('RRP: £' . $product->price .'. You pay: £' .$product->price * $product->discountmod)
                            ->image(str_replace('.app','.me',str_replace('http','https',$product->imageurl)))
                            ->addButton(\Mpociot\BotMan\Facebook\ElementButton::create('Order!')->type('postback')->payload('order'))
                            ->addButton(\Mpociot\BotMan\Facebook\ElementButton::create('Skip!')->type('postback')->payload('skip'))
                            ->addButton(\Mpociot\BotMan\Facebook\ElementButton::create('End order!')->type('postback')->payload('end'))
                    ]);
                $this->ask($quest,function(Answer $a){
                        if($a->getText() == "order")
                        {
                            $this->ask('How many would you like to order?', function (Answer $response) {
                                if(ctype_digit($response->getText()))
                                {
                                    $op = new OrderProduct();
                                    $user = $this->bot->userStorage()->get();
                                    $prod = $user->get('product');
                                    Log::emergency($this->order);
                                    Log::emergency($prod);
                                    $op->order_id = $this->order->id;
                                    Log::emergency("179");
                                    $op->product_code = $prod['code'];
                                    Log::emergency("181");
                                    $op->quantity = intval($response->getText());
                                    Log::emergency("183");
                                    $op->price = $prod['price'] * $prod['discountmod'];
                                    $op->currency = "gbp";
                                    $op->save();
                                    $this->ask('Do you want to add more products to your order? Say YES or NO', [
                                        [
                                            'pattern' => 'yes|yep|sure|ok|alright',
                                            'callback' => function ($op) {
                                                $this->say('Okay - we\'ll keep going');
                                                array_push($this->ops,$op);
                                                $this->askForProduct();
                                            }
                                        ],
                                        [
                                            'pattern' => 'nah|no|nope',
                                            'callback' => function ($op) {
                                                $this->say('OK! Moving on...');
                                                array_push($this->ops,$op);
                                                $this->anyCustom();
                                            }
                                        ]
                                    ]);

                                }
                                else
                                {
                                    $this->say('Sorry, that was an invalid response. Try entering a number that you would like to order');
                                    $this->repeat();
                                }
                            });
                        }
                        elseif ($a->getText() == "skip")
                        {
                            $this->askForProduct();
                        }
                        elseif($a->getText() == "end")
                        {
                            $this->anyCustom();
                        }
                    //continue
                });
            }
            catch(ModelNotFoundException $e)
            {
                $this->say('Sorry, this was not a valid product code. Please try again');
                $this->repeat();
            }

        });

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
                        $this->order->custom = $response->getText();
                        $this->deliveryType();
                    });
                }
                else{
                    $this->deliveryType();
                }
            }
            else{
                $this->deliveryType();
            }
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
        $this->ask($question, function (Answer $answer) {
            // Detect if button was clicked:
            if ($answer->isInteractiveMessageReply()) {
                $this->order->delivery = $answer->getValue();
                if($this->order->delivery == "delivery")
                {
                    $this->sayAddress();
                }
                else{
                    $this->askAnyLastDetails();
                }
            }
            else{
                $this->deliveryType();
            }
        });
        //Show them their last/default delivery address, change?

    }
    public function sayAddress()
    {
        $this->say('Now, let\'s check your delivery address is up to date. I think it is:');
        $c = new Client();
        $addr = json_decode($c->get('https://playdale.me/api/address/'.$this->uid)->getBody()->getContents());//TODO:Write this method on API
        $this->say('We currently have your address listed as');
        $this->say($addr['line1']);
        $this->say($addr['line2']);
        $this->say($addr['city']);
        $this->say($addr['postcode']);
        $this->say($addr['country']);
        $this->ask('Was this delivery address correct?', [
            [
                'pattern' => 'yes|yep|sure|ok|alright',
                'callback' => function ($addr) {
                    $this->say('Okay - we\'ll keep going');
                }
            ],
            [
                'pattern' => 'nah|no|nope',
                'callback' => function () {
                    $address = [];
                    $this->ask('Please enter the first line of your new delivery address', function (Answer $response) {
                        $address['line1'] = $response;
                        $this->ask('Please enter the second line of your new delivery address. If you don\'t have one, please say no!', function (Answer $response) {
                            if($response == ("no"|"nah"|"nope"))
                            {
                                $address['line2'] = null;
                            }
                            else{
                                $address['line2'] = $response;
                            }
                            $this->ask('Please enter the city of your new delivery address', function (Answer $response) {
                                $address['city'] = $response;
                                $this->ask('Please enter the postcode of your new delivery address', function (Answer $response) {
                                    $address['postcode'] = $response;
                                    $this->ask('Please enter the country of your new delivery address', function (Answer $response) {
                                        $address['country'] = $response;
                                        $this->say('Thanks!');
                                    });
                                });
                            });
                        });
                    });
                }
            ]
        ]);
        $this->order->address_line1 = $addr['line1'];
        $this->order->address_line2 = $addr['line2'];
        $this->order->city = $addr['city'];
        $this->order->postcode = $addr['postcode'];
        $this->order->country = $addr['country'];
        $this->askAnyLastDetails();
    }
    public function askAnyLastDetails()
    {
        $this->ask('Are there any custom details or incoterms you want to add to the order?', function (Answer $response) {
            $this->order->incoterms = $response->getText();
            $this->showFinalInvoice();
            //confirm
        });
    }
    public function showFinalInvoice()
    {
        try{
            $rt = ReceiptTemplate::create()
                ->recipientName($this->order->contact_name)
                ->merchantName('Playdale')
                ->orderNumber("ORDER PENDING")
                ->orderUrl('http://playdale.app/admin/orders')
                ->currency($this->order->currency)
                ->addAddress(ReceiptAddress::create()
                    ->street1($this->order->address_line1)
                    ->street2($this->order->address_line2)
                    ->city($this->order->city)
                    ->postalCode($this->order->postcode)
                    ->country($this->order->country)
                )
                ->addSummary(ReceiptSummary::create()
                    ->subtotal($this->order->order_total)
                    ->shippingCost($this->order->shipping_total)
                    ->totalCost($this->order->shipping_total + $this->order->order_total)
                );
            foreach ($this->ops as $item)
            {
                $imageurl = $this->getImageUrl($item->product_code);
                $rt->addElement(ReceiptElement::create($item->product_code)->quantity($item->quantity)->price($item->price)->image($imageurl));
            }
            $this->bot->reply(
                $rt
            );
            $this->confirm();

        }
        catch(ErrorException $e)
        {
            $this->say('Argh! I had a horrible error confirm your order. Sorry, please try again later!');
        }

    }
    public function createNewOrder()
    {
        $this->order = new Order();
        $this->say('Great! Thanks for starting a new order!');
        $this->say('*Ruffles Paperwork* Ok! Ready!');
        //New order name
        $this->getOrderName();
        //PO reference

    }
    public function confirm()
    {
        //you don't actually save the order, dumbass
        $this->say('Thanks for using my powers! Please come back soon! Bye!');
    }
    /**
     * Start the conversation
     */
    public function run()
    {
        $this->user = $this->bot->getUser();
        //Check Auth Status
        $this->uid = $this->user->getId();
        $as = $this->checkAuthStatus($this->user->getId());
        if(!$as)
        {
            $this->say('Hello '.$this->user->getFirstName().' '.$this->user->getLastName());
            $this->say("I'm sorry, but my powers are reserved only for authorised users of Playdale's EOS system trial. If you think you should be authorised, please visit EOS to link your account, or contact Playdale for more details");
            $this->say("https://playdale.me/user/settings");
        }
        else
        {
            $this->say('Hello '.$this->user->getFirstName().' '.$this->user->getLastName());
            $this->say('I am PlayBot, PEOS\'s facebook companion! Let\'s get started!');
            $this->askPurpose();


        }

    }



    /**
     * Start the conversation
     */


}
