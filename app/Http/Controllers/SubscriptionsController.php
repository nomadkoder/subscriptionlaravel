<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Subscription;
use Webpatser\Uuid\Uuid;
use App\Models\Team;
use App\Models\File;
use App\Models\AccountAccess;
use DB;

class SubscriptionsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request){

      if($request->input('action') == 'manual'){

        // manual subscription
        return DB::table('users')
                     ->leftJoin('subscriptions', 'users.id', '=', 'subscriptions.user_id')
                     ->select(DB::raw('users.id as user_id, subscriptions.user_id as user_id2, users.first_name, users.last_name, users.email, subscriptions.id as id, `subscriptions`.name as name, `subscriptions`.stripe_id, `subscriptions`.stripe_plan, `subscriptions`.ends_at, `subscriptions`.created_at, `subscriptions`.updated_at, `users`.updated_at as user_updated'))
                     ->whereRaw("(subscriptions.stripe_id IS NULL || subscriptions.stripe_id = 'manual')")
                     ->orderBy('users.updated_at', 'desc')
                     ->get();

      }

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {


        if($request->input('action') == 'manual'){

          // manual subscription

          try {

            $data = $request->all();
            $data = $data['fields'];

            //check first if a manual subscription has been created on this plan
              $data['stripe_id'] = 'manual';
              $data['stripe_plan'] = $data['name'];
              $data['quantity'] = 1;

              $subscription = new Subscription($data);
              $subscription->save();

              $subscription->first_name = $data['first_name'];
              $subscription->last_name  = $data['last_name'];
              $subscription->email      = $data['email'];

              return $subscription;


          } catch(\Exception $e) {
            $data = ['status' => 'error', 'msg' => $e->getMessage()];
            return response()->json($data, 400);
          }



        } else {
          return $this->subscribeUser($request);
        }



    }

    private function createNewSubscription(Request $request, $user, $stripeToken, $planChanged=false) {


      //new subscription
      $userAccount = config('account');
      $plan       = $request->input('plan.plan_id');
      $userSubs   = $user->subscriptions;
      $couponCode = NULL;
      $stripeName = $oldSubID = $stripePlan = false;
      $subQty     = 1;
      $oldSubQty  = $user->user_limit;

      if($userSubs){

        for($i=0;$i<count($userSubs);$i++){
          if($userSubs[$i]['stripe_id'] == 'manual_trial'){
            $oldSubID   = $userSubs[$i]['id'];
            $couponCode = $userSubs[$i]['coupon_code'];
            $stripeName = $plan;  //$userSubs[$i]['name'];
            $stripePlan = $plan.'_'.$user->currency; //$userSubs[$i]['stripe_plan'];
            $subQty     = ($request->input('plan.subscriptionQuantity') > 0) ? $request->input('plan.subscriptionQuantity') : $userSubs[$i]['quantity'];
          }
        }

        if($oldSubID > 0){

          if($user->subscription_type == 'club' || ($user->subscription_type == 'personal'
                    && ($request->input('plan.plan_id') == 'multisport_club' || $request->input('plan.plan_id') == 'combined_club')
            )){

            $clubData = [
              'stripeName'  => $stripeName,
              'stripePlan'  => $stripePlan,
              'stripeToken' => $stripeToken,
              'couponCode'  => $couponCode,
              'subQty'      => $subQty,
            ];

            $clubSubscription = $this->subscribeToClub($user, $clubData, $planChanged);

            if($clubSubscription){
              //create new subscription record

              $user = $user->refresh();

              $newSub = new Subscription();
              $newSub->user_id      = $user->id;
              $newSub->account_id   = $userAccount->id;
              $newSub->stripe_id    = $clubSubscription->id;
              $newSub->stripe_plan  = $stripePlan;
              $newSub->name         = $stripeName;
              $newSub->quantity     = $subQty;
              $newSub->coupon_code  = $couponCode;
              $newSub->save();
              $newSub = $newSub->refresh();

              $accountAccess = NULL;

              if($userAccount->subdomain !== 'app'){
                $accountAccessInfo = AccountAccess::leftJoin('accounts', 'accounts.id', '=', 'account_access.account_id')
                                   ->where('account_access.account_id',$userAccount->id)
                                   ->whereRaw('IF(account_access.expires_at is not null OR account_access.expires_at != "",DATE(CURDATE()) <= DATE(account_access.expires_at),1)')
                                   ->where('account_access.default_access',1)
                                   ->first();
                $accountAccess =  $accountAccessInfo ? $accountAccessInfo->uuid : NULL;
              }

              //update subscription id from team and delete previous one
              DB::table('teams')->where('subscription_id',$oldSubID)->update(['subscription_id' => $newSub->id, 'account_id' => $userAccount->id, 'account_access' => $accountAccess]);


            }

          } else {

            if(isset($couponCode) && $this->isCouponValid($couponCode)){
              //with valid coupon
              $user->newSubscription($stripeName, $stripePlan)
               ->withCoupon($couponCode)
               ->create($stripeToken, [
                   'email' => $user->email,
               ]);
            } else {
              $user->newSubscription($stripeName, $stripePlan)->create($stripeToken, [
                  'email' => $user->email,
              ]);
            }

          }



          $newSub = Subscription::where('stripe_plan',$stripePlan)->where('user_id',$user->id)->where('stripe_id', '<>', 'manual_trial')->first();

          if($newSub){
            $user = User::find($user->id);

            //check if subscription type has changed
            $selectedPlanType = stripos($plan, $user->subscription_type);
            $oldSubscriptionType = $user->subscription_type;
            if($selectedPlanType === false) {
              //update subscription type
              $user->subscription_type = $user->subscription_type == 'personal' ? 'club' : 'personal';
              $user->save();
              $user = $user->refresh();

              //if old subscription type = 'personal', generate default team folder
              if($oldSubscriptionType == 'personal'){
                $this->generateTeamFolder($request, false);
              }

            }

            //delete previous subscription data
            Subscription::where('id',$oldSubID)->delete();
            //remove trial_ends_at in User
            $user->update(['trial_ends_at' => NULL]);

          }

          $user = $user->refresh();
          return $user->load(['subscriptions']);


        } else {

          $data = ['status' => 'error', 'msg' => __('messages.sub_already')];
          return response()->json($data, 400);

        }

      }

      return $user;

    }

    public function subscribeToClub($user, $data, $planChanged=false)
    {
        \Stripe\Stripe::setApiKey(\Config::get('services.stripe.secret'));

        try {

              if(is_null($user->stripe_id)){
                //generate customer id first
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'source' => $data['stripeToken'],
                ]);

                if($customer && $customer->id){

                  $user->stripe_id = $customer->id;
                  $user->user_limit = $data['subQty'];
                  $user->save();
                  $user = $user->refresh();

                }


              }

              if(!is_null($user->stripe_id)){

                $baseFeePlan = $data['stripeName'].'_licence_'.$user->currency;
                $taxPercent  = $user->currency == 'gbp' ? 20 : 0;

                $planItems = [
                    [
                       'plan'     => $baseFeePlan, //base fee
                       'quantity' => 1,
                    ],
                    [
                        'plan'     => $data['stripePlan'],
                        'quantity' => $data['subQty'],
                    ],
                  ];


                $subscribed = \Stripe\Subscription::create([
                    'customer' => $user->stripe_id,
                    'coupon'   => $data['couponCode'],
                    'tax_percent' => $taxPercent,
                    'items' => $planItems,
                ]);



                return $subscribed;

              } else {

                return false;
              }




        } catch(\Exception $e) {
          $data = ['status' => 'error', 'msg' => $e->getMessage()];
          return response()->json($data, 400);
        }
    }


    private function isCouponValid($coupon)
    {
        \Stripe\Stripe::setApiKey(\Config::get('services.stripe.secret'));

        try {

            $coupon = \Stripe\Coupon::retrieve($coupon);
            return $coupon->valid;

        } catch(\Exception $e) {
          $data = ['status' => 'error', 'msg' => $e->getMessage()];
          return response()->json($data, 400);
        }
    }

    private function subscribeUser(Request $request){

      $user = $request->user();
      $userSubs = $user->subscriptions;
      $purchaseNow = false;
      $plan = $request->input('plan.plan_id');
      $stripeToken = $request->input('plan.token.id');
      $couponCode  = $request->input('plan.coupon_code');

      if($userSubs && count($userSubs) > 0){
        for($i=0;$i<count($userSubs);$i++){
          if($userSubs[$i]['stripe_id'] == 'manual_trial'){
            $purchaseNow = true;
          }
        }
      }

      //check first if we're subscribing to the same plan from trial
      $planType = explode('_',$plan,2);
      $planChanged = false;
      if($planType && count($planType) > 1 && strtolower($planType[1]) != $user->subscription_type){
        $planChanged = true;
      }


      //check first if user already subscribed to this plan, if not proceed
      if (!$purchaseNow && !$planChanged) {
        try {

          if(!$user->subscribed($plan)){
            //create new subscription
            if ($request->has('plan.coupon_code')) {

              if($this->isCouponValid($couponCode)){

                $subscribed = $user->newSubscription($plan, $plan.'_'.$user->currency)
                 ->withCoupon($couponCode)
                 ->create($stripeToken, [
                     'email' => $user->email,
                 ]);
              }

            } else {

              $subscribed = $user->newSubscription($plan, $plan.'_'.$user->currency)->create($stripeToken, [
                  'email' => $user->email,
              ]);
            }

            if($subscribed && $user->subscription_type == 'club'){

              $this->generateTeamFolder($request);

            } else {
              $activeSubscriptions = $user->subscriptions;
              $activeSubscriptions[] = $subscribed;
              $user->subscriptions = $activeSubscriptions;
            }

            return response()->json($user, 200);
          }


        } catch(\Stripe\Error\InvalidRequest $e) {
            //dd($e->getMessage());
            $data = ['status' => 'error', 'msg' => $e->getMessage()];
            return response()->json($data, 400);
        }

      } else {

        //for manual_trial
        return $this->createNewSubscription($request, $user, $stripeToken, $planChanged);

      }

    }

    private function generateTeamFolder(Request $request, $updateQty=true){

      $userAccount = config('account');
      $user   = $request->user();
      $data   = $request->all();
      $plan   = $data['plan']['plan_id'];

      $userUpdated = User::find($user->id);

      if($updateQty && (!array_key_exists('action',$data['plan']) || (array_key_exists('action',$data['plan']) && $data['plan']['action'] != 'upgrade'))){

        $this->updateQuantity($user, $plan, $data['plan']['subscriptionQuantity']);

      }

      $user = $userUpdated->load(['subscriptions']);
      $currentSub = Subscription::where('stripe_plan',$plan.'_'.$user->currency)->where('user_id',$user->id)->first();
      $accountAccess = NULL;

      if($userAccount->subdomain !== 'app'){
        $accountAccessInfo = AccountAccess::leftJoin('accounts', 'accounts.id', '=', 'account_access.account_id')
                           ->where('account_access.account_id',$userAccount->id)
                           ->whereRaw('IF(account_access.expires_at is not null OR account_access.expires_at != "",DATE(CURDATE()) <= DATE(account_access.expires_at),1)')
                           ->where('account_access.default_access',1)
                           ->first();
        $accountAccess =  $accountAccessInfo ? $accountAccessInfo->uuid : NULL;
      }

      //create team
      $folder_name = 'All Members - '.ucwords(str_replace('_',' ',$plan));
      $team	= new Team();
      $team->uuid     = Uuid::generate()->string;
      $team->owner_id = $user->id;
      $team->master_group = 1;
      $team->subscription_id = $currentSub ? $currentSub->id : null;
      $team->account_id = $userAccount->id;
      $team->account_access = $accountAccess;
      $team->group_name = $folder_name; //default
      $team->name  = $plan; //name col also serves as key, this should always be equal to subscriptions.name
      $team->save();

      /**
       * Teams = Groups
       * @var [type]
       */
      $userUpdated->teams()->attach($team->id);

      //create a default "All Members" folder
      $allMembers = File::create(['user_id' => $user->id, 'name' => $folder_name, 'team' => 1, 'team_root' => 1, 'type' => 'folder']);

      if($allMembers){
        //create relation to group/team
        DB::table('team_folder_group')->insert(['team_folder_uuid' => $allMembers->uuid, 'team_id' => $team->id, 'read_only' => 0, 'crud_access' => 1, 'active' => 1]);
      }

      return true;
    }


    private function updateQuantity($user, $planID, $qty){

      $currentSub = $user->subscription($planID);
      $newQty     = ($currentSub->quantity + $qty);

      if($currentSub && $currentSub->stripe_id){

             \Stripe\Stripe::setApiKey(\Config::get('services.stripe.secret'));

             //force update quantity
             $stripeSub = \Stripe\Subscription::retrieve($currentSub->stripe_id);

             if($stripeSub){
               $stripeUpdated = \Stripe\Subscription::update($currentSub->stripe_id, [
                 'items' => [
                       [
                           'id'   => $stripeSub->items->data[1]->id,
                           'plan' => $currentSub->stripe_plan,
                           'quantity' => $newQty,
                       ],
                   ],
               ]);

               if($stripeUpdated){
                 //generate invoice now!
                 \Stripe\Invoice::create([
                     'customer'    => $user->stripe_id,
                     'tax_percent' => $user->currency == 'gbp' ? 20 : null,
                 ]);
               }


             }

       }


       $user->user_limit = $newQty;
       $user->save();
       $currentSub->quantity = $newQty;
       $currentSub->save();

       return $currentSub->refresh();

    }

    private function upgradePlan($user, $currentSub, $data){

      if($currentSub && $currentSub->stripe_id && $currentSub->stripe_id != 'manual_trial' && $currentSub->stripe_id != 'manual'){


             \Stripe\Stripe::setApiKey(\Config::get('services.stripe.secret'));

             //force update quantity
             $stripeSub = \Stripe\Subscription::retrieve($currentSub->stripe_id);
             $baseFeePlan = $data['name'].'_licence_'.$user->currency;
             $taxPercent  = $user->currency == 'gbp' ? 20 : 0;

             if($stripeSub){
               $stripeUpdated = \Stripe\Subscription::update($currentSub->stripe_id, [
                 'tax_percent' => $taxPercent,
                 'items' => [
                       [
                           'id'   => $stripeSub->items->data[0]->id,
                           'plan' => $baseFeePlan,
                       ],
                       [
                           'plan'     => $data['stripe_plan'],
                           'quantity' => $data['quantity'],
                       ],
                   ],
               ]);

               if($stripeUpdated){
                 //generate invoice now!
                 \Stripe\Invoice::create([
                     'customer'    => $user->stripe_id,
                     'tax_percent' => $user->currency == 'gbp' ? 20 : null,
                 ]);
               }


             }

       }


       return $currentSub->refresh();

    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string $planID
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $planID)
    {
      $user = $request->user();
      $data = $request->all();
      $selectedPlan = array_key_exists('plan',$data) && $data['plan'] ? $data['plan'] : false;

        if($request->input('action') == 'manual' || ($selectedPlan && $selectedPlan['action'] == 'upgrade')){

          try {

            $subscription = false;

            if($selectedPlan && $selectedPlan['action'] == 'upgrade'){
              $data = $selectedPlan;
              $userSubs = $user->subscriptions;

              if($userSubs && count($userSubs) > 0){
                $subscription = $userSubs[0];

                $data = [
                  'id' => $subscription->id,
                  'subscription_plan' => $data['plan_id'],
                  'quantity' => intval($data['quantity']) == 0 ? 1 : intval($data['quantity']),
                ];

              }

            } else {
              $data = $data['fields'];
              $subscription = Subscription::find($data['id']);
            }

            if($subscription){

              if($request->has('updateTrial')){
                if(isset($data['subscription_plan']) && isset($data['ends_at'])){
                  $updatedData = [
                    'name'        => $data['subscription_plan'],
                    'stripe_plan' => $data['subscription_plan'].'_'.$user->currency,
                    'quantity'    => $data['user_limit'],
                    'ends_at'     => $data['ends_at']
                  ];
                  $subscription->update($updatedData);
                  $user = User::find($subscription->user_id);
                  $user->user_limit = $data['user_limit'];
                  $user->save();
                  $user = $user->refresh();
                  return $user->load(['subscriptions']);
                } else {
                  $data = ['status' => 'error', 'msg' => 'Subscription Plan or End Date not provided.'];
                  return response()->json($data, 400);
                }

              } else {
                //check first if user already is subscribed to this plan
                $subscribed = Subscription::leftJoin('users', 'subscriptions.user_id', '=', 'users.id')
                             ->select(DB::raw('subscriptions.*'))
                             ->whereRaw("subscriptions.id != ? AND subscriptions.name = ?",[$data['id'],$data['subscription_plan']])
                             ->where('user_id',$subscription->user_id)
                             ->first();

                if(!$subscribed){

                  if($selectedPlan && $selectedPlan['action'] == 'upgrade'){
                    //update subscription type
                    $user->subscription_type = 'club';
                    if($data['quantity'] && $data['quantity'] > 0){
                      $user->user_limit = $data['quantity'];
                    }
                    $user->save();
                    $user = $user->refresh();


                  }


                  $data['name']        = $data['subscription_plan'];
                  $data['stripe_plan'] = $data['subscription_plan'].'_'.$user->currency;
                  unset($data['subscription_plan']);

                  //update plan!
                  if($selectedPlan && $selectedPlan['action'] == 'upgrade'){
                    //change plan
                    $this->upgradePlan($user,$subscription,$data);

                  } else {
                    $user->subscription($subscription->name)->swap($data['stripe_plan']);
                  }


                  $subscription->update($data);
                  $subscription = $subscription->refresh();

                  if($selectedPlan && $selectedPlan['action'] == 'upgrade'){
                    $this->generateTeamFolder($request, false);
                  }

                  //$user = User::find($subscription->user_id);
                  $user = $user->refresh();
                  return $user->load(['subscriptions']);


               }
              }

            } else {
              $data = ['status' => 'error', 'msg' => 'You are already subscribed to this plan.'];
              return response()->json($data, 400);
            }


         } catch(\Exception $e) {
            $data = ['status' => 'error', 'msg' => $e->getMessage()];
            return response()->json($data, 400);
          }


        } else {

          if($user->subscription_type == 'club' && $request->input('plan.action') == 'incrementQty' && $request->input('plan.quantity') > 0){

           try {

             //$userUpdated = User::find($user->id);
             $currentSub = $user->subscription($planID);
             $newQty     = ($currentSub->quantity + $request->input('plan.quantity'));

             if($currentSub->stripe_id == 'manual_trial'){

               $currentSub->update(['quantity' => $newQty]);
               $user->user_limit = $newQty;
               $user->save();


             } else {

               //$currentSub->incrementQuantity($request->input('plan.quantity'));

               $this->updateQuantity($user, $planID, $request->input('plan.quantity'));

             }

              $user->load(['subscriptions']);

              $user->success = 'success';
              $user->msg = $request->input('plan.quantity').' licenses added to your account';

             return $user;

           } catch(\Stripe\Error\InvalidRequest $e) {
               //dd($e->getMessage());
               $data = ['status' => 'error', 'msg' => $e->getMessage()];
               return response()->json($data, 400);
           }

         } else {

           //check if plan has been cancelled and still on its grace period
           if($user->subscription($planID)->cancelled() && $user->subscription($planID)->onGracePeriod()){

             try {

                 // resume subscription
                $user->subscription($planID)->resume();
                return $user;


             } catch(\Stripe\Error\InvalidRequest $e) {
                 //dd($e->getMessage());
                 $data = ['status' => 'error', 'msg' => $e->getMessage()];
                 return response()->json($data, 400);
             }


           } else {

             $data = ['status' => 'error', 'msg' => __('messages.sub_resume_unable')];
             return response()->json($data, 400);


           }

         }

        }

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string $planID
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $planID)
    {
      $user = $request->user();

      //check first if user already subscribed to this plan, if yes, proceed cancellation
      if ($user->subscribed($planID)) {
        try {

            $user->subscription($planID)->cancel();
            return $user;


        } catch(\Stripe\Error\InvalidRequest $e) {
            //dd($e->getMessage());
            $data = ['status' => 'error', 'msg' => $e->getMessage()];
            return response()->json($data, 400);
        }

      } else {
        $data = ['status' => 'error', 'msg' => __('messages.sub_been_cancelled')];
        return response()->json($data, 400);
      }
    }
}
