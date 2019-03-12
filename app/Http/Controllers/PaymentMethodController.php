<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\User;
use App\Models\Subscription;
use Carbon\Carbon;
use DB;

class PaymentMethodController extends Controller
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

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string $planID
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $user        = $request->user();
        $user->subscriptions;
        $stripeToken = $request->input('token.id');

        try {

          if(($user->subscription_type == 'personal' || $user->subscription_type == 'club') && ($user->stripe_id == null || !isset($user->stripe_id))) {

            $user = $this->createNewSubscription($user, $stripeToken);

           } else {
             // update Card
            $user->updateCard($stripeToken);
           }

           //return $user;
           $data = ['status' => 'success', 'msg' => __('messages.payment_updated'), 'user' => $user];
           return response()->json($data, 200);

        } catch(\Stripe\Error\InvalidRequest $e) {
            //dd($e->getMessage());
            $data = ['status' => 'error', 'msg' => $e->getMessage()];
            return response()->json($data, 400);
        }

    }

    private function createNewSubscription($user, $stripeToken) {
      //new subscription
      $userSubs = $user->subscriptions;
      $couponCode = false;
      $oldSubID   = false;
      $stripePlan = false;
      $subQty     = 1;

      if($userSubs){

        for($i=0;$i<count($userSubs);$i++){
          if($userSubs[$i]['stripe_id'] == 'manual_trial'){
            $oldSubID   = $userSubs[$i]['id'];
            $couponCode = $userSubs[$i]['coupon_code'];
            $stripeName = $userSubs[$i]['name'];
            $stripePlan = $userSubs[$i]['stripe_plan'];
            $subQty     = $userSubs[$i]['quantity'];
          }
        }

        if($oldSubID > 0){
          if($couponCode && $this->isCouponValid($couponCode)){
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

          $newSub = Subscription::where('stripe_plan',$stripePlan)->where('user_id',$user->id)->where('stripe_id', '<>', 'manual_trial')->first();

          if($newSub){
            $user = User::find($user->id);

            if($user->subscription_type == 'club'){
              $user->subscription($stripeName)->updateQuantity($subQty);
            }


            //update subscription id from team and delete previous one
            $teamUpdated = DB::table('teams')->where('subscription_id',$oldSubID)->update(['subscription_id' => $newSub->id]);

            if($teamUpdated){
              //delete previous subscription data
              Subscription::where('id',$oldSubID)->delete();
              //remove trial_ends_at in User
              $user->update(['trial_ends_at' => NULL]);

            }


          }



          return $user->load(['subscriptions']);


        } else {

          $data = ['status' => 'error', 'msg' => __('messages.sub_already')];
          return response()->json($data, 400);

        }

      }

      return $user;

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



}
