<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Price;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Metarequest;
use App\Models\Withdrawal;
use App\Models\Packages;
use App\Models\Matchingincome;
use Response;
use Auth;
use PhpParser\Builder\Trait_;
use Session;
use Illuminate\Support\Carbon;
class DashboardController extends Controller
{
        
    public function logout(Request $request) { 
      Auth::logout();
      $request->session()->invalidate();
      $request->session()->regenerateToken();      
      return redirect('indexNew');
    }
    public function checkUser(Request $request ) {
      $data      = $request->all();
      $findCheck = User::where("wallet_address",$data['wallet_address'])->count();
      if($findCheck==1){
          $userData       = Auth::user();
          if( $userData->wallet_address != $data['wallet_address'] ){ 
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return Response::json(array('type' => "error", 'msg'   => "user error 2" )); 
          }else{
            return Response::json(array('type' => "success", 'msg'   => "user error 3" )); 
          }          
      }else{
          return Response::json(array('type' => "error", 'msg'   => "user error 1" )); 
      }
    }
    public function users() {
        if( (Auth::user()) && (Session::getId()==Auth::user()->session_id)  ){
          $user_id         = Auth::user()->id;
          $userData        = User::where("id",$user_id)->first();
          $farmingWithda   = Withdrawal::whereIN("status",["Pending","Success"])->where("trans",1)->where("user_id",$user_id)->sum("amount");
          $farmingIncome   = 0;
          $userInvest      = 0;
          $investData      = Transaction::where("user_id",$user_id)->where("trans",0)->get();
          foreach($investData as $key => $inv){
            $userInvest   += $inv['amount'];
            $today         = date("Y-m-d");
            $to            = \Carbon\Carbon::createFromFormat('Y-m-d', $inv['planExpiryDate']);
            $from          = \Carbon\Carbon::createFromFormat('Y-m-d', date("Y-m-d",strtotime($inv['created_at'])));
            $diff_in_month = $to->diffInMonths($from);
            ///echo $diff_in_month."</br>";
            $multiplier    = 1;
            if($diff_in_month==12){
              $multiplier  = 1.5;
            }else if($diff_in_month==18){
              $multiplier  = 2;
            }else if($diff_in_month ==24){
              $multiplier  = 3;
            }
            if( $today >= $inv['planExpiryDate'] ){
              $farmingIncome+= $inv['amount']*$multiplier;
              $investData[$key]['packageComplete'] =1; 
            }else{
              $investData[$key]['packageComplete'] =0; 
            }
            
          } 
          $latestPackage =Transaction::where("user_id",$user_id)->where('trans', 0)->get();
          // dd($latestPackage);
          $level_str   = $userData['level_str'];
          //team size
          $teamSize = User::select("id")->where("id",">",$user_id)->where("level_str","LIKE", $level_str."%")->get();
          $activeId = isset($latestPackage->from_package);
          
          $totalRoi =Transaction::where('from_package',$activeId)->where('trans', 1)->sum('amount');
          // dd($totalRoi);
          $levelIncomes = Transaction::where('user_id',$user_id)->where('trans',2)->get();

          $farmingIncome   = $farmingIncome-$farmingWithda;
          //stacking dividend
          $userRoi         = Transaction::where("user_id",$user_id)->where("trans",1)->sum("amount");
          //level income
          $userLevel       = Transaction::where("user_id",$user_id)->where("trans",2)->sum("amount");
          //DIrect income
          $userReferral    = Transaction::where("user_id",$user_id)->where("trans",3)->sum("amount");
          //incentive income
          $incentiveIncome       = Transaction::where("user_id",$user_id)->where("trans",4)->sum("amount");
          
          $matchingIncome  = Matchingincome::where("user_id",$user_id)->sum("amount");
          $totalWithdrawal = Withdrawal::whereIN("status",["Pending","Success"])->where("trans",0)->where("user_id",$user_id)->sum("amount");
          $totalIncome     = $userRoi+$userLevel+$userReferral+$matchingIncome+$incentiveIncome;

          $grnadTotal      = $totalIncome - $totalWithdrawal;
          $settings        = Setting::where("id",1)->first();
          $priceData       = Price::where("id",1)->first();
          $packages        = Package::where("status",1)->get();

          $levelTrans      = Transaction::select('type')->where("user_id",$user_id)->where("trans",2)->groupBy('type')->selectRaw('sum(amount) as amount, type')->get()
            ;
          
          //Level user count
          $level_str   = $userData->level_str;
          $levelArr    = [];
          $addStrin    = "+[0-9]+,";          
          $addStrin1   = "";
          for($i=1;$i<11; $i++){
            $addStrin1  .= $addStrin;   
            $levelCnt    = User::whereRaw(" level_str REGEXP '^".$level_str.$addStrin1."$'")->count();
            array_push( $levelArr,$levelCnt);
          }
          return view('users',compact('levelArr','levelTrans','settings','packages','farmingIncome','priceData','userData','userLevel',"incentiveIncome",'userInvest','userRoi','userReferral','totalIncome','grnadTotal','totalWithdrawal','matchingIncome','investData','latestPackage', 'totalRoi' ,'levelIncomes' ,'teamSize' ) );
        }else{
          return redirect('indexNew');
        }        
    }
    public function checkPackageRequest(Request $request) {
      $data           = $request->all(); 
      $wallet_address = $data['wallet_address'];
      //$pId            = $data['pId'];
      $amount         = $data['amount'];
      
      $priceData      = Price::where("id",1)->first();
      
      $minAmt         = 10;

      if( ($amount < $minAmt) && ($amount != $minAmt) ){
        return Response::json(array('type' => "error", 'msg'   => "Minimum amount error" ));
      }
      $checkUser      = User::select("id")->where("status",1)->where("wallet_address",$wallet_address)->first();
      if($checkUser){
        $user_id      = $checkUser->id;
        $checkDays    = Metarequest::select('id')->where('trans_type',"Package invest")->where("user_id",$user_id)->where("status","Pending")->first();
        if($checkDays){
          return Response::json(array('type' => "error", 'msg'   => "Check package error 4" ));
        }else{
          $oldPackage  = Transaction::select("id","amount")->where("trans",0)->where("user_id",$user_id)->orderBy("id","ASC")->first();
          if($oldPackage ){
            /*if($oldPackage['amount'] >= $amount){
              return Response::json(array('type' => "error", 'msg'   => "Check package error 5" ));
            }*/
          }
          return Response::json(array('type' => "success", 'msg'   => "go","tokenAmt"=> (int)$amount/$priceData['tokenValue'], "amount"=> $amount) );     
        }
      }else{
        return Response::json(array('type' => "error", 'msg'   => "Check package error 3" )); 
      } 
    }
    public function addPackageRequest(Request $request) {
      $data           = $request->all(); 
      $trans_id       = $data['trans_id'];
      $amount         = $data['amount'];
      $wallet_address = $data['wallet_address'];
      $usdAmt         = $data['usdAmt'];
     // $pId            = $data['pId'];
      $checkUser      = User::select("id")->where("status",1)->where("wallet_address",$wallet_address)->first();
      if($checkUser){
        $user_id      = $checkUser['id'];
        $transCheck    = Transaction::select("id")->where("trans_id",$trans_id)->count();
        if($transCheck==0){ 
          $MetaArr     = array('trans_type'=> "Package invest",'wallet_address'=>$wallet_address,'user_id'=>$user_id,'tokenAmt'=>$amount,'amount'=>$usdAmt,'trans_id'=>$trans_id,"status"=>"Pending" );
          Metarequest::create($MetaArr); 
          return Response::json(array('type' => "success", 'msg'   => "Success" ));
        }else{
            return Response::json(array('type' => "error", 'msg'   => "Package error 1" ));
        }
       
      }else{
        return Response::json(array('type' => "error", 'msg'   => "Package error 3" )); 
      } 
    }
    
    





    public function withRe(Request $request){    
      $today         = date("Y-m-d");  
      $data          = $request->all(); 
      $amt           = $data['amt'];
      $wallet        = $data['wallet_address'];
      $trans         = $data['trans'];
      $userData      = Auth::user();
      $user_id       = Auth::user()->id; 
      $settings      = Setting::where("id",1)->first();
      if( (Auth::user()) && (Session::getId()==Auth::user()->session_id)  ){
        $withRequest     = Withdrawal::where("user_id",$user_id)->where("status","Pending")->count();
        if($withRequest >0 ){
          return Response::json(array('type' => "error",'msg'   => "Withdrwal error 3" ));  
        }
        if($trans ==1){
          $farmingWithda   = Withdrawal::whereIN("status",["Pending","Success"])->where("trans",1)->where("user_id",$user_id)->sum("amount");
          $farmingIncome   = 0;
          $investData      = Transaction::where("user_id",$user_id)->where("trans",0)->get();
          foreach($investData as $key => $inv){            
            $to            = \Carbon\Carbon::createFromFormat('Y-m-d', $inv['planExpiryDate']);
            $from          = \Carbon\Carbon::createFromFormat('Y-m-d', date("Y-m-d",strtotime($inv['created_at'])));
            $diff_in_month = $to->diffInMonths($from);
            $multiplier    = 1;
            if($diff_in_month==12){
              $multiplier  = 1.5;
            }else if($diff_in_month==18){
              $multiplier  = 2;
            }else if($diff_in_month ==24){
              $multiplier  = 3;
            }
            if( $today >= $inv['planExpiryDate'] ){
              $farmingIncome+= $inv['amount']*$multiplier;
            }
          } 
          $totalIncome     = $farmingIncome;
          $doneWithamt     = $farmingWithda;
        }else{
          $totalIncome     = User::WithdrawaableIncome($user_id);
          $doneWithamt     = Withdrawal::whereIN("status",["Pending","Success"])->where("user_id",$user_id)->where("trans",0)->sum("amount");
        } 
        $withAmtDB       = (int)($totalIncome-$doneWithamt);
        
        if($wallet != $userData->wallet_address){
          return Response::json(array('type' => "error",'msg'   => "Withdrwal error 1" ));  
        }
        if($withAmtDB < 1 ){
          return Response::json(array('type' => "error",'msg'   => "Withdrwal error 2" ));  
        }
        $priceData    = Price::where("id",1)->first();
        $withAmt      = (int)$withAmtDB; 
        //$tokenAmt     = (int)($withAmt*$priceData['tokenValue']);
        $tokenAmt     = $withAmt;
        $postFields   = array("wallet_address"=>$wallet,"amount"=>$tokenAmt,"tokenAddress"=>$settings['TOKEN_ADDRESS']);
        $passValue    = json_encode($postFields);
        $curl         = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $settings['WITHDRAWAL_API'],
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => $passValue,
          CURLOPT_HTTPHEADER => array(
          'Content-Type: application/json'
          ),
        )); 
        $response  = curl_exec($curl);
        $curl_response = $response; 
        $response  = json_decode($response);
        if($response->success==true)
        {
            Withdrawal::create( [ "trans"=>$trans, "user_id"=>$user_id,"amount"=>$withAmt,"trans_id"=>$response->data,"status"=>"Pending"] );
            return Response::json(array('type' => "success",'msg'   => "withdrawal successfully.")); 
        }else{
            return Response::json(array('type' => "error",'msg'   => "Withdrwal amount not set." ));  
        }
        
      }else{
        return Response::json(array('type' => "error",'msg'   => "Please login first.." )); 
      }
    } 
    public function withupdate() {
      $requestData     = Withdrawal::select("amount","id","trans_id","user_id","curl_response","checkCount")->where("status","Pending")->where("checkCount","<",6)->get();
      foreach( $requestData as $key=>$trans){
        $id          = $trans->id;
        $user_id     = $trans->user_id;
        $trans_id    = $trans->trans_id;
        $amount      = $trans->amount; 
        $curl_response= $trans->curl_response; 
        $checkCount  = $trans->checkCount; 

        $curl           = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => env('TRON_API_URL'),
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS =>'{"value": "'.$trans_id.'"}',
          CURLOPT_HTTPHEADER => array(
          'Content-Type: application/json'
          ),
        ));
        $response       = curl_exec($curl);
        curl_close($curl);
        $responseDecode = json_decode($response,true);
        $curl_response  = $curl_response.",".$response;

        $status       = "";       
        if(isset($responseDecode["ret"][0]["contractRet"]) && !empty($responseDecode["ret"][0]["contractRet"]) ){
          $status       = $responseDecode["ret"][0]["contractRet"];
        }
        ////Request Update
        if(empty($responseDecode)){
          Withdrawal::where("id",$id)->update( ["status"=>"Pending","checkCount"=>($checkCount+1), "curl_response"=>$curl_response] );
        }
        else if(isset($responseDecode["Error"]) && !empty($responseDecode["Error"])){
          Withdrawal::where("id",$id)->update( ["status"=>"Pending","checkCount"=>($checkCount+1), "curl_response"=>$curl_response] );
        }          
        else if($status != "SUCCESS"){
          Withdrawal::where("id",$id)->update( ["status"=>"Pending","checkCount"=>($checkCount+1), "curl_response"=>$curl_response] );
        }else { 
          Withdrawal::where("id",$id)->update( ["status"=>"Success","checkCount"=>($checkCount+1), "curl_response"=>$curl_response] );  
        }
      }
      Withdrawal::where("status","Pending",)->where("checkCount",6)->update( ["amount"=>0,"status"=>"Failed"] );      
    }
    function userBusinesss($userId,$businessPosition){
      $bussiness   = 0;
      $findUser    = User::where("binaryParent",$userId)->where("position",$businessPosition)->first();
      if($findUser){
        $binary_str  = $findUser['binary_str'];
        $bussiness   = Transaction::where("trans",0)->whereIn('user_id', function($query) use ($binary_str)
                                {
                                    $query->select("id")
                                          ->from('users')
                                          ->where("binary_str","like",$binary_str."%");
                                })->sum("amount");
      }
      return $bussiness;
    }
    public function userTree(Request $request ) {
      $formData       = $request->all();
      $user_id        = $formData['user_id'];
      $selfData_1     = User::select("id","packageAmt","wallet_address","registerId","created_at")->where("id",$user_id)->first();
      $leftuser       = User::where("binaryParent",$user_id)->where("position","Left")->first();
      $rightuser      = User::where("binaryParent",$user_id)->where("position","Right")->first();
      $leftCount      = 0 ;
      $rightCount     = 0 ;
      $self_left_bussiness   = 0;
      $self_right_bussiness  = 0;
      $left_left_bussiness   = 0;
      $left_right_bussiness  = 0;
      $left_total_bussiness  = 0;

      $right_left_bussiness   = 0;
      $right_right_bussiness  = 0;
      $right_total_bussiness  = 0;

      $left_1_left_bussiness   = 0;
      $left_1_right_bussiness  = 0;
      $left_1_total_bussiness  = 0;

      $leftData      = array();
      $left_left_Data      = array();
      $left_right_Data      = array();
      $rightData     = array();

      $right_left_Data  = array();
      $right_right_Data  = array();
      //Left Team
      if($leftuser){
        $self_left_binary_str  = $leftuser['binary_str'];
        $leftCount    = User::where("binary_str","like",$leftuser['binary_str']."%")->count();
        
        
        $self_left_bussiness   = Transaction::where("trans",0)->whereIn('user_id', function($query) use ($self_left_binary_str)
                                      {
                                          $query->select("id")
                                                ->from('users')
                                                ->where("binary_str","like",$self_left_binary_str."%");
                                      })->sum("amount");
        ///first left business calculation
        $left_left_bussiness  =  $this->userBusinesss($leftuser['id'],"Left");
        $left_right_bussiness =  $this->userBusinesss($leftuser['id'],"Right");
        $left_total_bussiness  = $left_left_bussiness+$left_right_bussiness;
                                      

        $leftData     = array("left_id"=>$leftuser['id'], 
                      "left_amount"=>$leftuser['packageAmt'], 
                      "left_created_at"=>date("Y-m-d",strtotime($leftuser['created_at'])),
                      "wallet_address"=>$leftuser['wallet_address'],
                      "registerId"=>$leftuser['registerId'],
                      "left_left_bussiness"=>$left_left_bussiness,
                      "left_right_bussiness"=>$left_right_bussiness,
                      "left_total_bussiness"=>$left_total_bussiness
                    );

        $left_left_user_found        = User::where("binaryParent",$leftuser['id'])->where("position","Left")->first();
        if($left_left_user_found){
            ///first left-1 business calculation
            $left_1_left_bussiness   =  $this->userBusinesss($left_left_user_found['id'],"Left");
            $left_1_right_bussiness  =  $this->userBusinesss($left_left_user_found['id'],"Right");
            $left_1_total_bussiness  = $left_1_left_bussiness+$left_1_right_bussiness;
            $left_left_Data          = array("left_id"=>$left_left_user_found['id'], 
                                        "left_amount"=>$left_left_user_found['packageAmt'], 
                                        "left_created_at"=>date("Y-m-d",strtotime($left_left_user_found['created_at'])),
                                        "wallet_address"=>$left_left_user_found['wallet_address'],
                                        "registerId"=>$left_left_user_found['registerId'],
                                        "left_left_bussiness"=>$left_1_left_bussiness,
                                        "left_right_bussiness"=>$left_1_right_bussiness,
                                        "left_total_bussiness"=>$left_1_total_bussiness
                                      );
        }
        $left_right_user_found       = User::where("binaryParent",$leftuser['id'])->where("position","Right")->first();
        if($left_right_user_found){
            ///first left-1 business calculation
            $left_2_left_bussiness   =  $this->userBusinesss($left_right_user_found['id'],"Left");
            $left_2_right_bussiness  =  $this->userBusinesss($left_right_user_found['id'],"Right");
            $left_2_total_bussiness  = $left_2_left_bussiness+$left_2_right_bussiness;
            $left_right_Data         = array("left_id"=>$left_right_user_found['id'], 
                                        "left_amount"=>$left_right_user_found['packageAmt'], 
                                        "left_created_at"=>date("Y-m-d",strtotime($left_right_user_found['created_at'])),
                                        "wallet_address"=>$left_right_user_found['wallet_address'],
                                        "registerId"=>$left_right_user_found['registerId'],
                                        "left_left_bussiness"=>$left_2_left_bussiness,
                                        "left_right_bussiness"=>$left_2_right_bussiness,
                                        "left_total_bussiness"=>$left_2_total_bussiness
                                      );
        }
      }
      //Right Team
      if($rightuser){
        $self_right_binary_str = $rightuser['binary_str'];       
        $self_right_bussiness  = Transaction::where("trans",0)->whereIn('user_id', function($query) use ($self_right_binary_str)
                                    {
                                        $query->select("id")
                                              ->from('users')
                                              ->where("binary_str","like",$self_right_binary_str."%");
                                    })->sum("amount"); 
        $rightCount    = User::where("binary_str","like",$rightuser['binary_str']."%")->count();


        ///first Right business
        $right_left_bussiness  =  $this->userBusinesss($rightuser['id'],"Left");
        $right_right_bussiness =  $this->userBusinesss($rightuser['id'],"Right");       
        $right_total_bussiness  = $right_left_bussiness+$right_right_bussiness;


        $rightData    = array("left_id"=>$rightuser['id'], 
                      "right_amount"=>$rightuser['packageAmt'], 
                      "right_created_at"=>date("Y-m-d",strtotime($rightuser['created_at'])),
                      "wallet_address"=>$rightuser['wallet_address'],
                      "registerId"=>$rightuser['registerId'],
                      "right_left_bussiness"=>$right_left_bussiness,
                      "right_right_bussiness"=>$right_right_bussiness,
                      "right_total_bussiness"=>$right_total_bussiness
                    );

        $right_left_user_found        = User::where("binaryParent",$rightuser['id'])->where("position","Left")->first();
        if($right_left_user_found){
            ///first left-1 business calculation
            $right_1_left_bussiness   =  $this->userBusinesss($right_left_user_found['id'],"Left");
            $right_1_right_bussiness  =  $this->userBusinesss($right_left_user_found['id'],"Right");
            $right_1_total_bussiness  = $right_1_left_bussiness+$right_1_right_bussiness;
            $right_left_Data          = array("left_id"=>$right_left_user_found['id'], 
                                        "left_amount"=>$right_left_user_found['packageAmt'], 
                                        "left_created_at"=>date("Y-m-d",strtotime($right_left_user_found['created_at'])),
                                        "wallet_address"=>$right_left_user_found['wallet_address'],
                                        "registerId"=>$right_left_user_found['registerId'],
                                        "left_left_bussiness"=>$right_1_left_bussiness,
                                        "left_right_bussiness"=>$right_1_right_bussiness,
                                        "left_total_bussiness"=>$right_1_total_bussiness
                                      );
        }
        $right_right_user_found       = User::where("binaryParent",$rightuser['id'])->where("position","Right")->first();
        if($right_right_user_found){
            ///first left-1 business calculation
            $right_2_left_bussiness   =  $this->userBusinesss($right_right_user_found['id'],"Left");
            $right_2_right_bussiness  =  $this->userBusinesss($right_right_user_found['id'],"Right");
            $right_2_total_bussiness  = $right_2_left_bussiness+$right_2_right_bussiness;
            $right_right_Data         = array("left_id"=>$right_right_user_found['id'], 
                                        "left_amount"=>$right_right_user_found['packageAmt'], 
                                        "left_created_at"=>date("Y-m-d",strtotime($right_right_user_found['created_at'])),
                                        "wallet_address"=>$right_right_user_found['wallet_address'],
                                        "registerId"=>$right_right_user_found['registerId'],
                                        "left_left_bussiness"=>$right_2_left_bussiness,
                                        "left_right_bussiness"=>$right_2_right_bussiness,
                                        "left_total_bussiness"=>$right_2_total_bussiness
                                      );
        }

      }
      $self_total_bussiness  = $self_left_bussiness+$self_right_bussiness;
      $selfData       = array("self_id"=>$selfData_1['id'], "self_amount"=>$selfData_1['packageAmt'], "self_created_at"=>date("Y-m-d",strtotime($selfData_1['created_at'])),"wallet_address"=>$selfData_1['wallet_address'],"registerId"=>$selfData_1['registerId'],"leftCount"=>$leftCount,"rightCount"=>$rightCount,"self_left_bussiness"=>$self_left_bussiness,"self_right_bussiness"=>$self_right_bussiness,"self_total_bussiness"=>$self_total_bussiness);

      $data           = array("selfData"=>$selfData,"leftCount"=>$leftCount,"rightCount"=>$rightCount,"leftData"=>$leftData,"rightData"=>$rightData,"left_left_Data"=>$left_left_Data,"left_right_Data"=>$left_right_Data,"right_left_Data"=>$right_left_Data,"right_right_Data"=>$right_right_Data);
      return Response::json(array('type' => "success", 'data' => $data ));      
    }

   }