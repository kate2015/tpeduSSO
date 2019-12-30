<?php

namespace App\Http\Controllers;

use Auth;
use Log;
use App\User;
use App\StudentParentData;
use App\StudentParentRelation;
use App\StudentParentsQrcode;
use App\Thirdapp;
use App\OauthScopeField;
use App\OauthThirdappStudent;
use DB;
use Config;
use Notification;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Providers\LdapServiceProvider;
use App\Providers\GoogleServiceProvider;
use App\Rules\idno;
use App\Notifications\AccountChangeNotification;
use App\Notifications\PasswordChangeNotification;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
//        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('home');
    }
    
    public function showProfileForm()
    {
		return view('auth.profile', [ 'user' => Auth::user() ]);
    }

	public function resourceDownloadForm()
	{
		return view('resourcedownload');
	}

    public function changeProfile(Request $request)
    {
		$openldap = new LdapServiceProvider();
		$email = $request->get('email');
		$mobile = $request->get('mobile');
		$user = Auth::user();
		$idno = $user->idno;

		$userinfo = array();
		if ($email && $email != $user->email) {
	    	$validatedData = $request->validate([
			    'email' => 'required|email|unique:users',
			]);
	    	if (!$openldap->emailAvailable($idno, $email))
				return back()->withInput()->with("error","您輸入的電子郵件已經被別人使用，請您重新輸入一次！");
	    	$userinfo['mail'] = $email;
	    	$user->email = $email;
		}
		if (!$email) {
    		$userinfo['email'] = array();
    		$user->email = null;
		}
		if ($mobile && $mobile != $user->mobile) {
	    	$validatedData = $request->validate([
			    'mobile' => 'nullable|string|digits:10|numeric',
			]);
			if (!$openldap->mobileAvailable($idno, $mobile))
				return back()->withInput()->with("error","您輸入的手機號碼已經被別人使用，請您重新輸入一次！");
    		$userinfo['mobile'] = $mobile;
    		$user->mobile = $mobile;
		}
		if (!$mobile) {
    		$userinfo['mobile'] = array();
    		$user->mobile = null;
		}
		//$user->save();

		$entry = $openldap->getUserEntry($idno);
		if(!empty($entry)){
			$result = $openldap->updateData($entry, $userinfo);
			if($result) $user->save();
			else return back()->withInput()->with("error", "無法變更人員資訊！".$openldap->error());
			$accounts = $openldap->getUserAccounts($idno);
			if ($request->get('login-by-email', 'no') == "yes" && !empty($email)) $accounts[] = $email;
			if ($request->get('login-by-mobile', 'no') == "yes" && !empty($mobile)) $accounts[] = $mobile;
			$accounts = array_values(array_unique($accounts));
			$openldap->updateData($entry, array( 'uid' => $accounts));
			$openldap->updateAccounts($entry, $accounts);

			//順便更新家長table,如果有的話...
			$info = \App\ParentsInfo::where('cn',$idno)->first();
			if($info){
				$info->mail = $user->email;
				$info->mobile = $user->mobile;
				$info->save();
			}
		}else{
			$info = \App\ParentsInfo::where('cn',$idno)->first();
			if($info){
				$info->mail = $user->email;
				$info->mobile = $user->mobile;
				$info->save();
				$user->save();
			}else return back()->withInput()->with("error", "無法變更人員資訊！找不到人員資訊");
		}

		return back()->withInput()->with("success","您的個人資料設定已經儲存！");
    }

	public function checkGsuiteAccount($username,$idno)
	{
		/*
		//暫時停用
		$openldap = new LdapServiceProvider();
		if($username)
			$idno = $openldap->checkAccount($username);

        if ($idno) {
			$openldap = new LdapServiceProvider();
			$entry = $openldap->getUserEntry($idno);
			if($entry){
				$data = $openldap->getUserData($entry, 'entryUUID');
				if($data){
					$u = User::where('uuid',$data['entryUUID'])->first();
					if($u && !empty($u->gsuite_email)){
						$u->is_change_account = '1';
						$u->save();
						return false;
					}
				}
			}
		}
		*/

		return true;
	}

    public function showChangeAccountForm(Request $request)
    {
		if (Auth::check()) {
			$user = Auth::user();
			$username=$user->uname;
		} else {
			$username=$request->session()->get('username');
		}
		$isChangeAccount = '0';

		if (!$this->checkGsuiteAccount($username,null))
			return view('auth.changeaccount')->with(['username' => $username, 'is_change_account' => '1', 'error' => "已註冊過G-Suite帳號，不可以變更帳號！"]);

		return view('auth.changeaccount')->with("username",$username)->with("is_change_account",$isChangeAccount);
    }

    public function changeAccount(Request $request)
    {
		if (Auth::check()) {
			$user = Auth::user();
			$idno = $user->idno;
		} else {
		  $idno = $request->session()->get('idno');
		}

		if (!$this->checkGsuiteAccount(null,$idno))
			return back()->withInput();

		$validatedData = $request->validate([
			'new-account' => 'required|alpha_num|min:6',
		]);
		$new = $request->get('new-account');
		$openldap = new LdapServiceProvider();
		$accounts = $openldap->getUserAccounts($idno);
		foreach ($accounts as $account) {
    		if ($new == $account) return back()->withInput()->with("error","新帳號不可以跟舊的帳號相同，請重新想一個新帳號再試一次！");
		}
		if($idno == $new) return back()->withInput()->with("error","新帳號不可以跟身分證字號相同，請重新想一個新帳號再試一次！");
		if (!$openldap->accountAvailable($new)) return back()->withInput()->with("error","您輸入的".$new."帳號已經被別人使用，請您重新輸入一次！");
		$entry = $openldap->getUserEntry($idno);
		$data = $openldap->getUserData($entry, 'mail');
		if (empty($accounts)) {
			$openldap->addAccount($entry, $new, "自建帳號");
			if (Auth::check()) {
				$user->uname = $new;
				$user->save();
				if (!empty($user->email)) $user->notify(new PasswordChangeNotification($new));
			} else {
				$user = User::where('idno', $idno)->first();
				if ($user) {
					$user->uname = $new;
					$user->save();
				}
				if (isset($data['mail'])) Notification::route('mail', $data['mail'])->notify(new AccountChangeNotification($new));
			}
			return back()->withInput()->with("success","帳號建立成功！");
		} else {
			$openldap->renameAccount($entry, $new);
			if (Auth::check()) {
				$user->uname = $new;
				$user->save();
				if (!empty($user->email)) $user->notify(new PasswordChangeNotification($new));
			} else {
				$user = User::where('idno', $idno)->first();
				if ($user) {
					$user->uname = $new;
					$user->save();
				}
				if (isset($data['mail'])) Notification::route('mail', $data['mail'])->notify(new AccountChangeNotification($new));
			}
			//G-Suite Account add Alias 
			//if (!empty($user->email) && stripos($user->email,Config::get('saml.email_domain'))>0) {
			if(!empty($user->gsuite_email)) {
				$gs = new GoogleServiceProvider();

				try{
					$new_email = $new."@".Config::get('saml.email_domain');
					$result = $gs->queryUserByEmail($user->gsuite_email);
					//在g-suite真的有帳號
					if($result){
						$result = $gs->queryUserByEmail($new_email);
						if($result)
							return back()->withInput()->with("error",$new."已經有人使用，無法變更！");

						try{
							$result = $gs->createUserAlias($user->gsuite_email,$new_email);
							Log::info('User '.$user->idno.' add G-Suite alias '.$new_email.' success');
						}catch (\Exception $e){
							Log::debug('User '.$user->idno.' add G-Suite alias:'.$new_email.' failed:'.$e->getMessage());
						}
					}
				}catch (\Exception $e){
					Log::debug('G-Suite Account ('.$user->email.') failed:'.$e->getMessage());
				}
			}

			if ($openldap->userLogin("cn=$idno", substr($idno,-6)) && !Auth::check()){
				$request->session()->put('idno', $idno);
				return redirect()->route('changePassword')->with("success","帳號變更成功，要先修改密碼才能執行後續作業！");
			} else {
				$request->session()->invalidate();
				return redirect('login')->with("success","帳號變更成功，請重新登入！");
			}	
		}
    }

    public function showChangePasswordForm()
    {
		return view('auth.changepassword');
    }

    public function changePassword(Request $request)
    {
		if (Auth::check()) {
			$user = Auth::user();
			$idno = $user->idno;
		} else {
		    $idno = $request->session()->get('idno');
		}
		$new = $request->get('new-password');
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($idno);
		$data = $openldap->getUserData($entry);
		if ($openldap->userLogin("cn=$idno", $new))
	    	return back()->withInput()->with("error","新密碼不可以跟舊的密碼相同，請重新想一個新密碼再試一次！");
		$validatedData = $request->validate([
			'new-password' => 'required|string|min:8|regex:/^.*(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\X])(?=.*[!@$#%&]).*$/|confirmed',
		]);
		if (Auth::check()) {
			$user->resetLdapPassword($new);
			$user->password = \Hash::make($new);
			$user->save();
			if (!empty($user->email)) $user->notify(new PasswordChangeNotification($new));
		} else {
			$openldap->resetPassword($entry, $new);
			$user = User::where('idno', $idno)->first();
			if ($user) {
				$user->password = \Hash::make($new);
				$user->save();
			}
			if (isset($data['mail'])) Notification::route('mail', $data['mail'])->notify(new PasswordChangeNotification($new));
		}
		//改密碼後要先登出
		if (Auth::check()) {
			Auth::logout();
		} 
		try {
			$request->session()->invalidate();
		} catch (\Exception $e){            
        }
		return redirect('login')->with("success","密碼變更成功，請重新登入！");
	}

	public function teacherLessons (Request $request)
	{
		$data = [];
		$openldap = new LdapServiceProvider();
		$me = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$lessons = array_key_exists('tpTeachClass',$me) ? $me['tpTeachClass']:null;

		if(is_array($lessons) && count($lessons) > 0){
			$csid = [];
			$csids = \App\TeacherClassroom::where('uuid',Auth::user()->uuid)->get();
			foreach($csids as $cs)
				if(!empty($cs->enrollment_code) && !empty($cs->alternate_link))
					$csid[$cs->subjkey] = ['code' => $cs->enrollment_code, 'link' => $cs->alternate_link];

			$dcs = [];
			foreach($lessons as $les){
				if(is_string($les))
					$es = explode (",", $les);
				if(isset($es) && is_array($es) && sizeof($es) == 3){
					if(!in_array($es[0], $dcs))
						array_push($dcs, $es[0]);
					array_push($data, array_merge(['dc' => $es[0], 'cls' => $es[1], 'clsName' => '', 'subj' => $es[2], 'subjName' => ''], array_key_exists($les,$csid)?$csid[$les]:['code' => '', 'link' => '']));
				}
			}

			$classes = [];
			$subjs = [];
			foreach($dcs as $dc){
				$cc = $openldap->getOus($dc, '教學班級');
				$classes[$dc] = array();
				foreach($cc as $c)
					if(is_string($c->description) && !empty($c->description))
						$classes[$dc][$c->ou] = $c->description;

				$cc = $openldap->getSubjects($dc);
				$subjs[$dc] = array();
				foreach($cc as $c)
					if(is_string($c['description']) && !empty($c['description']))
						$subjs[$dc][$c['tpSubject']] = $c['description'];
			}

			$count = count($data);
			for($i=0;$i<$count;$i++){
				$d = $data[$i];
				$data[$i]['clsName'] = (array_key_exists($d['dc'],$classes) && array_key_exists($d['cls'],$classes[$d['dc']]))?$classes[$d['dc']][$d['cls']]:$d['cls'];
				$data[$i]['subjName'] = (array_key_exists($d['dc'],$subjs) && array_key_exists($d['subj'],$subjs[$d['dc']]))?$subjs[$d['dc']][$d['subj']]:$d['subj'];
			}
		}

		$gsuite = empty(\App\User::where('uuid',Auth::user()->uuid)->first()->gsuite_created_at)?'N':'Y';

		return view('admin.personalteacherlessons', ['data' => $data, 'gsuite' => $gsuite]);
	}

    public function lessonsMember(Request $request)
    {
		$dc = $request->get('dc');
		$cls = $request->get('cls');
		$subj = $request->get('subj');

		$data = [];
		if(!empty($dc) && is_string($dc) && !empty($cls) && is_string($cls) && !empty($subj) && is_string($subj)){
			$subjkey = $dc.','.$cls.','.$subj;
			$openldap = new LdapServiceProvider();
			$teas = $openldap->findUsers('tpTeachClass='.$subjkey, ["entryUUID","displayName"]);

			if(!empty($teas)){
				$idx = [];
				for($i=0;$i<count($teas);$i++)
					array_push($idx,$teas[$i]['entryUUID']);

				$users = User::where('uuid',$idx)->get();
				$idx = [];
				foreach($users as $u){
					if(!empty($u->gsuite_email))
						$idx[$u->uuid] = $u->gsuite_email;
				}

				for($i=0;$i<count($teas);$i++)
					$teas[$i]['mail'] = array_key_exists($teas[$i]['entryUUID'],$idx)?$idx[$teas[$i]['entryUUID']]:'';
			}

			$data['teachers'] = $teas;

			$students = \App\StudentClasssubj::where('subjkey',$subjkey)->get();

			if(!empty($students)){
				$filter = '(|';
				foreach($students as $st)
					$filter .= '(entryUUID='.$st->uuid.')';
				$filter .= ')';
				$stud = $openldap->findUsers($filter, ["entryUUID","displayName"]);

				$idx = [];
				for($i=0;$i<count($stud);$i++)
					array_push($idx,$stud[$i]['entryUUID']);

				if(count($idx) > 0){
					$users = User::whereIn('uuid', $idx)->get();
					$idx = [];
					foreach($users as $u){
						if(!empty($u->gsuite_email))
							$idx[$u->uuid] = $u->gsuite_email;
					}
				}

				for($i=0;$i<count($stud);$i++)
					$stud[$i]['mail'] = array_key_exists($stud[$i]['entryUUID'],$idx)?$idx[$stud[$i]['entryUUID']]:'';

				$data['students'] = $stud;
			}
		}

		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	public function teacherCourses (Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$key = $request->get('subjkey');
		$name = $request->get('subjName');
		$brief = $request->get('brief');
		$pid = $request->get('pid');

		if(empty($key) || !is_string($key))
			return '{"error":"缺少課程！"}';

		$openldap = new LdapServiceProvider();
		$teas = $openldap->findUsers('tpTeachClass='.$key, ['entryUUID','cn']);
		$uuid = Auth::user()->uuid;
		$ownerId = null;
		$user = [];

		if(!empty($teas)){
			foreach ($teas as $t){
				$acc = \App\User::where('uuid',$t['entryUUID'])->first();

				if(!empty($acc) && !empty($acc->gsuite_email)){
					if($t['entryUUID'] == $uuid){
						$ownerId = $acc->gsuite_email;
					}else $user[] = $acc->gsuite_email;
				}
			}
		}

		if($ownerId == null)
			return '{"error":"未註冊G-Suite帳號，無法建立課程！"}';

		$tc = \App\TeacherClassroom::where('uuid',$uuid)->where('subjkey',$key)->first();

		if(!empty($tc) && !empty($tc->classroom_id))
			return '{"error":"已建立過此課程的Classroom，不可重複建立！"}';

		if(empty($name) || !is_string($name)){
			return '{"error":"課程名稱不可以空白！"}';
		}else if(!empty($brief) && !is_string($brief)){
			return '{"error":"錯誤的課程簡述！"}';
		}else if(!empty($pid) && !is_array($pid)){
			return '{"error":"錯誤的學生參數！"}';
		}else{
			$domain = config('saml.email_domain');

			//建立課程
			try{
				$gs = new GoogleServiceProvider();
				//$name, $section, $descriptionHeading, $description, $room, $ownerId, $courseState
				//ownerId:id, email or 'me'
				//PROVISIONED(default),ACTIVE,DECLINED
				$result = $gs->createCourse($name,null,null,$brief,null,$ownerId,null);
			}catch (\Exception $e){
				//$error = $e->getMessage();
				Log::debug('Create G-Suite Course failed:'.$e->getMessage().PHP_EOL.$name.PHP_EOL.$brief.PHP_EOL.$ownerId);
			}

			if(isset($result) && array_key_exists('alternateLink',$result) && array_key_exists('enrollmentCode',$result) && array_key_exists('id',$result)){
				$courseId = $result['id'];

				if(empty($tc)){
					$tc = new \App\TeacherClassroom();
					$tc->uuid = $uuid;
					$tc->subjkey = $key;
				}

				$tc->alternate_link = $result['alternateLink'];
				$tc->enrollment_code = $result['enrollmentCode'];
				$tc->classroom_id = $courseId;
				$tc->save();

				//為課程加入教師和學生
				$this->dispatch(new \App\Jobs\AddCourseMembers($courseId, $user, $pid, $key));

				if(count($pid) > 0){
					\Session::flash('success', '課程建立完成！勾選的學生將會陸續接到加入課程的邀請，每個學生約需2~3秒的時間。');
				}else \Session::flash('success', '課程建立完成！');

				return '{"success":"success","key":"'.$key.'"}';
			}else{
				if(isset($error) && !empty($error))
					return '{"error":"建立G-Suite課程失敗:'.$error.'"}';
				return '{"error":"建立G-Suite課程失敗！"}';
			}
		}
	}

	public function tutorStudent (Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$title = null;
		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));

		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;
		$dc = array_key_exists('o',$user) ? $user['o']:null;

		$sort = [];
		$data = [];
		if(!empty($cls) && !empty($dc)){
			$title = $openldap->getOuTitle($dc,$cls);

			if ($title) {
				$students = $openldap->findUsers("(&(o=$dc)(employeeType=學生)(tpClass=$cls))", ["entryUUID","inetUserStatus","uid","cn","displayName","tpClass","tpSeat","o","mail","mobile"]);

				$cc = 0;
				foreach ($students as $stu) {
					if(array_key_exists($stu['tpSeat'],$sort)){
						array_push($sort[$stu['tpSeat']],$cc);
					}else $sort[$stu['tpSeat']] = array($cc);

					$cc++;
				}

				ksort($sort);

				foreach($sort as $seat){
					foreach($seat as $s){
						$stu = $students[$s];
						$stu['status'] = ($stu['inetUserStatus'] == 'active') ? '啟用':'未啟用';
						$stu['tpSeat'] = str_pad($stu['tpSeat'],2,'0',STR_PAD_LEFT);
						if(is_array($stu['uid'])){
							if(array_key_exists('mail',$stu) && in_array($stu['mail'],$stu['uid']))
								array_splice($stu['uid'], array_search($stu['mail'],$stu['uid']), 1);
							if(array_key_exists('mobile',$stu) && in_array($stu['mobile'],$stu['uid']))
								array_splice($stu['uid'], array_search($stu['mobile'],$stu['uid']), 1);
							$stu['uid'] = count($stu['uid']) > 0?$stu['uid'][0]:'';
						}
						array_push($data,$stu);
					}
				}
			}
		}

		$expireDate = Config::get('app.parentsQRCodeExpireDays');

		return view('admin.personaltutorstudent', ['data' => $data, 'clsname' => $title, 'expire' => Carbon::now()->addDays($expireDate)->format('Y/m/d')]);
	}

    public function resetpwStudent(Request $request, $uuid)
    {
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$me = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		if(is_string($uuid))
			$entry = $openldap->getUserEntry($uuid);
		if(!isset($entry))
			return back()->with("error", "找不到學生！");

		$cls = array_key_exists('tpTutorClass',$me) ? $me['tpTutorClass']:null;
		$data = $openldap->getUserData($entry, array('o', 'cn', 'uid', 'displayName', 'employeeType', 'employeeNumber', 'tpClass', 'mail'));

		$dc = $data['o'];
		$idno = $data['cn'];
		$info = array();
		$info['userPassword'] = $openldap->make_ssha_password(substr($idno,-6));

		if (!array_key_exists('uid', $data) || empty($data['uid']) || !array_key_exists('employeeType', $data) || $data['employeeType'] != '學生') {
			return back()->with("error", "找不到學生！");
		}else if(empty($cls) || !array_key_exists('tpClass',$data) || strcmp($cls,$data['tpClass'])){
			return back()->with("error", "非導師不可以回復密碼！");
		}else{
			if (is_array($data['uid'])) {
				foreach ($data['uid'] as $account) {
					$account_entry = $openldap->getAccountEntry($account);
					$openldap->updateData($account_entry, $info);
				}
			} else {
				$account_entry = $openldap->getAccountEntry($data['uid']);
				$openldap->updateData($account_entry, $info);
			}
		}

		//設定密碼還原後的有效期
		//20191115密碼有效期移到people裡的initials
		$fp = Config::get('app.firstPasswordChangeDay');
		if(ctype_digit(''.$fp) && $fp > 0)
			$info['initials'] = Carbon::now()->addDays($fp)->format('Ymd');

		$result = $openldap->updateData($entry, $info);

		if ($result) {
			$user = User::where('idno', $idno)->first();
			if ($user) {
				$user->password = \Hash::make(substr($idno,-6));
				$user->save();
			}

			if(array_key_exists('mail', $data) && filter_var($data['mail'], FILTER_VALIDATE_EMAIL)){
				try{
					$text = $user->name.'您好'.PHP_EOL.PHP_EOL.'您的導師 '.$me['displayName'].' 已於 '.Carbon::now()->format('Y-m-d H:i:s').' 將您的登入密碼重設為身分證字號末六碼';
					\Mail::raw($text, function($message) use ($data)
					{
						$message->from(Config::get('mail.from.address'), Config::get('mail.from.name'));
						$message->to($data['mail'])->subject('臺北市教育人員單一身分驗證服務-密碼重設通知');
					});
				}catch (\Exception $e){
				}
			}

			/*\Queue::push(function($job) use ($text){
				file_put_contents('testlog.txt',$text.PHP_EOL,FILE_APPEND);
				$job->delete();
			});*/

			return back()->with("success", "已經將 ".$data['displayName']." 的密碼重設為身分證字號後六碼！");
		} else {
			return back()->with("error", "無法變更密碼！".$openldap->error());
		}
	}

    public function listmyparents(Request $request, $uuid)
    {
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$dc = array_key_exists('o',$user) ? $user['o']:null;
		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;

		if(empty($dc))
			return '{"error":"找不到所屬學校"}';
		if(empty($cls))
			return '{"error":"您不是班級導師"}';

		$title = $openldap->getOuTitle($dc,$cls);

		if(!$title)
			return '{"error":"找不到班級資料"}';

		if(is_string($uuid))
			$student = $openldap->getUserData($openldap->getUserEntry($uuid));

		if(empty($student))
			return '{"error":"找不到學生"}';
		if(!array_key_exists('tpClass',$student))
			return '{"error":"找不到學生班級"}';
		if(!$student['tpClass'] || $cls != $student['tpClass'])
			return '{"error":"您不是這位學生的導師"}';

		$data = [];
		$filter = '(&(objectClass=tpeduPerson)(|';
		$idnos = array();
		$names = array();

		//取得學生已連結的家長
		$pars = StudentParentRelation::where('student_idno', $student['cn'])->get();
		foreach ($pars as $p){
			array_push($names, $p->parent_name);
			$filter .= '(cn='.$p->parent_idno.')';
			$idnos[] = $p->parent_idno;
			array_push($data, (object)['sid' => $uuid, 'id' => $p->id, 'idno' => $p->parent_idno, 'name' => $p->parent_name, 'rel' => $p->parent_relation, 'linked' => $p->status, 'status' => '無', 'from' => 'R']);
		}

		//取得所有學生未連結的家長
		$pars = StudentParentData::where('student_idno', $student['cn'])->get();
		foreach ($pars as $p){
			if(!in_array($p->parent_name, $names)){
				$filter .= '(cn='.$p->parent_idno.')';
				$idnos[] = $p->parent_idno;
				array_push($data, (object)['sid' => $uuid, 'id' => $p->id, 'idno' => $p->parent_idno, 'name' => $p->parent_name, 'rel' => $p->parent_relation, 'linked' => '0', 'status' => '無', 'from' => 'D']);
			}
		}

		if(count($data) > 0){
			$info = \App\ParentsInfo::whereIn('cn',$idnos)->get();
			$idnos = array();
			foreach($info as $p)
				$idnos[$p->cn] = $p->user_status == 'active' ? '啟用':'未啟用';

			$filter .= '))';
			$parents = $openldap->findUsers($filter, ["inetUserStatus","cn"]);
			foreach($parents as $par)
				$idnos[$par['cn']] = $par['inetUserStatus'] == 'active' ? '啟用':'未啟用';

			if(count($idnos) > 0){
				foreach($data as $d){
					if(array_key_exists($d->idno, $idnos))
						$d->status = $idnos[$d->idno];
				}
			}
		}

		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}

    public function listparents(Request $request)
    {
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$dc = array_key_exists('o',$user) ? $user['o']:null;
		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;

		if(empty($dc))
			return '{"error":"找不到所屬學校"}';
		if(empty($cls))
			return '{"error":"您不是班級導師"}';

		$title = $openldap->getOuTitle($dc,$cls);

		if(!$title)
			return '{"error":"找不到班級資料"}';

		$data = [];

		//取得導師班上所有學生
		$students = $openldap->findUsers("(&(o=$dc)(employeeType=學生)(tpClass=$cls))", ["cn",'displayName','tpSeat']);

		$cns = array();
		foreach ($students as $stu)
			$cns[$stu['cn']] = (object)['seat' => str_pad($stu['tpSeat'],2,'0',STR_PAD_LEFT), 'sname' => $stu['displayName'], 'pars' => array()];

		//取得所有學生已連結的家長
		$pars = StudentParentRelation::whereIn('student_idno', array_keys($cns))->get();
		$rels = array();
		foreach ($pars as $p)
			array_push($rels, $p->student_idno.'-'.$p->parent_name);

		//取得所有學生未連結的家長
		$pars = StudentParentData::whereIn('student_idno', array_keys($cns))->where('parent_idno','<>','')->get();
		foreach ($pars as $p){
			if(!in_array($p->student_idno.'-'.$p->parent_name, $rels))
				array_push($cns[$p->student_idno]->pars, (object)['id' => $p->id, 'name' => $p->parent_name, 'rel' => $p->parent_relation]);
		}

		//依座號排序
		$sort = array();
		foreach ($cns as $k => $v) {
			if(array_key_exists($v->seat,$sort)){
				array_push($sort[$v->seat],$k);
			}else $sort[$v->seat] = array($k);
		}

		ksort($sort);

		foreach($sort as $seat){
			foreach($seat as $s){
				$obj = $cns[$s];
				foreach ($obj->pars as $o) {
					$o->seat = $obj->seat;
					$o->sname = $obj->sname;
					array_push($data, $o);
				}
			}
		}

		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	public function listparentsqrcode(Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$dc = array_key_exists('o',$user) ? $user['o']:null;
		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;

		if(empty($dc))
			return view('admin.schoolparentsqrcode', ['error' => '找不到所屬學校']);
		if(empty($cls))
			return view('admin.schoolparentsqrcode', ['error' => '您不是班級導師']);

		$title = $openldap->getOuTitle($dc,$cls);

		if(!$title)
			return view('admin.schoolparentsqrcode', ['error' => '找不到班級資料']);

		$pid = $request->get('pid');

		if(!isset($pid) || !is_array($pid))
			return view('admin.schoolparentsqrcode', ['error' => '無資料可列印']);

		//取得導師班上所有學生
		$students = $openldap->findUsers("(&(o=$dc)(employeeType=學生)(tpClass=$cls))", ['cn','tpClass','tpSeat','displayName','o']);

		$cns = array();
		foreach ($students as $stu)
			$cns[$stu['cn']] = (object)['cls' => $stu['tpClass'], 'seat' => str_pad($stu['tpSeat'],2,'0',STR_PAD_LEFT), 'sname' => $stu['displayName'], 'pars' => array()];

		//取得所有學生已連結的家長
		$pars = StudentParentRelation::whereIn('student_idno', array_keys($cns))->get();
		$rels = array();
		foreach ($pars as $p)
			array_push($rels, $p->student_idno.'-'.$p->parent_name);

		//取得所有學生未連結的家長
		$pars = StudentParentData::whereIn('student_idno', array_keys($cns))->where('parent_idno','<>','')->whereIn('id', $pid)->get();
		foreach ($pars as $p){
			if(!in_array($p->student_idno.'-'.$p->parent_name, $rels))
				array_push($cns[$p->student_idno]->pars, (object)['id' => $p->id, 'name' => $p->parent_name, 'rel' => $p->parent_relation]);
		}

		//依座號排序
		$sort = array();
		foreach ($cns as $k => $v) {
			if(array_key_exists($v->seat,$sort)){
				array_push($sort[$v->seat],$k);
			}else $sort[$v->seat] = array($k);
		}

		ksort($sort);

		$dt = Carbon::now()->addDays(Config::get('app.parentsQRCodeExpireDays'));
		$data = [];
		$insert = [];
		$url = $request->getSchemeAndHttpHost().'/';

		foreach($sort as $seat){
			foreach($seat as $s){
				$obj = $cns[$s];
				foreach ($obj->pars as $o) {
					$o->cls = $obj->cls;
					$o->seat = $obj->seat;
					$o->sname = $obj->sname;
					//$o->ss = Crypt::encrypt(json_encode($o,JSON_UNESCAPED_UNICODE));
					$guid = str_replace('-','',\Guid::create());
					$o->guid = $url.'linkqrcode?'.$guid.$o->id;

					array_push($insert, [
						'guid' => $guid,
						'dataid' => $o->id,
						'std_name' => $obj->sname,
						'std_cls' => $obj->cls,
						'std_seat' => $obj->seat,
						'par_name' => $o->name,
						'par_rel' => $o->rel,
						'expire_date' => $dt->format('Ymd'),
						'created_user' => Auth::user()->uuid,
						'created_at' => Carbon::now()
					]);

					array_push($data, $o);
				}
			}
		}

		DB::table('student_parents_qrcode')->insert($insert);

		return view('admin.schoolparentsqrcode', ['data' => $data, 'dt' => $dt->format('Y/m/d'), 'error' => '']);
	}

	public function linkqrcode(Request $request)
	{
		$guid = key($request->all());

		if(is_string($guid) && strlen($guid) > 32 && ctype_digit(substr($guid,32))){
			$data = StudentParentsQrcode::where('guid',substr($guid,0,32))->where('dataid',intval(substr($guid,32)))->first();

			if($data){
				$dt = Carbon::now()->format('Ymd');

				if(strcmp($data->expire_date,$dt) >= 0){
					$request->session()->put('qrcodeObject',$data);
					if(!Auth::user()){
						return redirect('login')->with("error","請先登入本系統");
					} else 	return $this->connectChildQrcode($request);
				}else{
					return redirect('login')->with("error","QR-CODE已失效");
				}
			}else{
				return redirect('login')->with("error","找不到QR-CODE資訊");
			}
		}
		return null;
	}

	public function linkedChange(Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$dc = array_key_exists('o',$user) ? $user['o']:null;
		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;

		if(empty($dc))
			return '{"error":"找不到所屬學校"}';
		if(empty($cls))
			return '{"error":"您不是班級導師"}';

		$title = $openldap->getOuTitle($dc,$cls);

		if(!$title)
			return '{"error":"找不到班級資料"}';

		$uuid = $request->get('uuid');
		$id = $request->get('id');
		$check = $request->get('c');

		if(is_string($uuid))
			$student = $openldap->getUserData($openldap->getUserEntry($uuid));

		if(empty($student))
			return '{"error":"找不到學生"}';
		if(!array_key_exists('tpClass',$student))
			return '{"error":"找不到學生班級"}';
		if(!$student['tpClass'] || $cls != $student['tpClass'])
			return '{"error":"您不是這位學生的導師"}';

		//取得學生已連結的家長
		if(is_string($id))
			$pars = StudentParentRelation::where('student_idno', $student['cn'])->where('id',$id)->first();

		if(!empty($pars)){
			$pars->status = $check=='Y'?'1':'0';
			$pars->save();
			return '{"success":1}';
		}else{
			return '{"error":"找不到家長資料"}';
		}
	}

	public function parentsqrcode(Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$dc = array_key_exists('o',$user) ? $user['o']:null;
		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;

		if(empty($dc))
			return '{"error":"找不到所屬學校"}';
		if(empty($cls))
			return '{"error":"您不是班級導師"}';

		$title = $openldap->getOuTitle($dc,$cls);

		if(!$title)
			return '{"error":"找不到班級資料"}';

		$uuid = $request->get('uuid');
		$id = $request->get('id');

		if(is_string($uuid))
			$student = $openldap->getUserData($openldap->getUserEntry($uuid));

		if(empty($student))
			return '{"error":"找不到學生"}';
		if(!array_key_exists('tpClass',$student))
			return '{"error":"找不到學生班級"}';
		if(!$student['tpClass'] || $cls != $student['tpClass'])
			return '{"error":"您不是這位學生的導師"}';

		//取得學生已連結的家長
		$pars = StudentParentRelation::where('student_idno', $student['cn'])->get();
		$rels = array();
		foreach ($pars as $p)
			array_push($rels, $p->parent_name);

		$url = $request->getSchemeAndHttpHost().'/';

		//取得學生未連結的家長
		if(is_string($id))
			$pars = StudentParentData::where('student_idno', $student['cn'])->where('parent_idno','<>','')->where('id',$id)->first();

		if(!empty($pars)){
			if(!in_array($pars->parent_name, $rels)){
				$guid = str_replace('-','',\Guid::create());
				$dt = Carbon::now()->addDays(Config::get('app.parentsQRCodeExpireDays'));

				$data = (object)[
					'cls' => $student['tpClass'],
					'seat' => $student['tpSeat'],
					'sname' => $student['displayName'],
					'name' => $pars->parent_name,
					'rel' => $pars->parent_relation,
					//'guid' => $url.'linkqrcode?'.$guid.$pars->id
					'base64' => base64_encode(\QrCode::format('png')->size(300)->margin(0)->encoding('UTF-8')->generate($url.'linkqrcode?'.$guid.$pars->id))
				];

				DB::table('student_parents_qrcode')->insert([
					'guid' => $guid,
					'dataid' => $pars->id,
					'std_name' => $student['displayName'],
					'std_cls' => $student['tpClass'],
					'std_seat' => $student['tpSeat'],
					'par_name' => $pars->parent_name,
					'par_rel' => $pars->parent_relation,
					'expire_date' => $dt->format('Ymd'),
					'created_user' => Auth::user()->uuid,
					'created_at' => Carbon::now()
				]);

				return json_encode($data, JSON_UNESCAPED_UNICODE);
			}else{
				return '{"error":"已有親子連結關係，無法列印QR-CODE"}';
			}
		}else{
			return '{"error":"找不到家長資料"}';
		}
	}
	
	public function listConnectChildren(Request $request)
	{
		$openldap = new LdapServiceProvider();
		if (Auth::check()) {
			$userNow = Auth::user();
		} else redirect()->back()->with("error","無法取得您的登入資訊，請重新登入，謝謝！")->withInput();	
		$data = StudentParentRelation::where('parent_idno',$userNow->idno)->orderBy('created_at','desc')->get();

		$idnos = [];
		$dataList = [];
		if(!empty($data)) {
			//設定學生資料
			foreach ($data as $d) {
				$cc=[];
				$entry = $openldap->getUserEntry($d['student_idno']);
				if ($entry) {
					$studentData = $openldap->getUserData($entry);
					$cc['student_name']=$studentData['displayName'];
					$cc['student_id']=$studentData['employeeNumber'];
					//學校 
					$o=$studentData['o'];
					$cc['school_name']=$openldap->getOrgTitle($o);
				}
				$cc['student_idno']=$d['student_idno'];
				$cc['status']= $d['status']=='1' ? '已連結' : '中斷連結';
				$cc['parent_relation']=$d['parent_relation'];
	
				$dataList[$d['id']]=$cc;
				$idnos[] = $d['student_idno'];
			}
		}

		//申請資料
		$data = \App\StudentParentApply::where('parent_idno',$userNow->idno)->orderBy('created_at','desc')->get();
		foreach ($data as $d) {
			if(!in_array($d['student_idno'],$idnos)){
				$cc=[];
				$entry = $openldap->getUserEntry($d['student_idno']);
				if ($entry) {
					$studentData = $openldap->getUserData($entry);
					$cc['student_name']=$studentData['displayName'];
					$cc['student_id']=$studentData['employeeNumber'];
					//學校 
					$o=$studentData['o'];
					$cc['school_name']=$openldap->getOrgTitle($o);
				}
				$cc['student_idno']=$d['student_idno'];
				if($d['status'] == '0'){
					$cc['status'] = '審核中';
				}else if($d['status'] == '1'){
					$cc['status'] = '同意';
				}else if($d['status'] == '2'){
					$cc['status'] = '不同意';
				}

				$cc['parent_relation']=$d['parent_relation'];

				$dataList[$d['id']]=$cc;
				$idnos[] = $d['student_idno'];
			}
		}

		return view('parents.connectchildren', ['data' => $dataList]);
	}

	public function showConnectChildForm(Request $request)
    {
		$areas = Config::get('app.areas');
		$schoolCategorys = Config::get('app.schoolCategory');
		
		if($request->old('area')!==null)  $area = $request->old('area');
			else $area = $request->get('area');
		if($request->old('schoolCategory')!==null)  $schoolCategory = $request->old('schoolCategory');
			else $schoolCategory = $request->get('schoolCategory');
		if($request->old('dc')!==null)  $dc = $request->old('dc');
			else $dc = $request->get('dc'); //school
		if (empty($area)) $area = $areas[0];
		if (empty($schoolCategory)) $schoolCategory = $schoolCategorys[0];
		if (empty($schoolCategory)) $filter = "st=$area";
		 else $filter = "(&(st=$area)(businessCategory=$schoolCategory))";
		
		$openldap = new LdapServiceProvider();
		$schools = $openldap->getOrgs($filter);

        return view('parents.connectchildedit', [  'idno'=>$request->get('idno'), 'student_id'=>$request->get('student_id'), 'student_birthday'=>$request->get('student_birthday'), 'pname'=>empty($request->get('pname'))?(Auth::user()->name):$request->get('pname'), 'email' => Auth::user()->email, 'mobile' => Auth::user()->mobile, 'relationType'=>$request->get('relationType'),'area' => $area, 'areas' => $areas, 'schools' => $schools, 'dc' => $dc, 'schoolCategorys' => $schoolCategorys, 'schoolCategory' => $schoolCategory ]);
	}
	
	public function connectChild(Request $request)
    {
		$attributes = [
            'idno' => '身分字號',            
			'student_id' => '學號',
			'student_birthday' => '出生年月日',
			'dc' => '學校',
			'relationType' => '親子關係',
			'pname' => '姓名',
		];
		
		$this->validate($request, [
            'idno' =>  ['required', new idno()],
            'student_id' => 'required|alpha_num',
            'student_birthday' => 'required|date|date_format:Ymd',
			'relationType' => 'required|string',
            'pname' => 'required|string',
        ],[],$attributes);

		$idno = $request->get('idno');
		$openldap = new LdapServiceProvider();

		if (Auth::check()) {
			$userNow = Auth::user();
		} else redirect()->back()->with("error","無法取得您的登入資訊，請重新登入，謝謝！")->withInput();	
		
		//檢查學生身分證是否有在LDAP
        if ($openldap->checkIdno($idno)) {
			//檢查學生資料是否正確
			$entry = $openldap->getUserEntry($idno);
			if ($entry) {
				$data = $openldap->getUserData($entry);
				$schid = $openldap->getOrgID($request->get('dc'));

				$userCheck = new \App\User();
				$userCheck->idno=$idno;
				//是否是學生
				if($userCheck->inRole('學生')) {
					if($data['employeeNumber'] == $request->get('student_id') && substr($data['birthDate'],0,8) == $request->get('student_birthday') && $data['o'] == $request->get('dc')) {
						$cntRelation = count(\App\StudentParentRelation::where('student_idno',$idno)->where('parent_idno',Auth::user()->idno)->get());
						if($cntRelation > 0)
							return redirect()->back()->with("error","該筆家長學生關連資料已綁定過，謝謝！")->withInput();

						//是否資料在DB有
						$stuParentData = StudentParentData::where('parent_name',$request->get('pname'))
						->where('school_id',$schid)
						//->where('parent_relation',$request->get('relationType'))
						->where('student_idno',$idno)
						//->where('student_id',$request->get('student_id'))//mysql裡的學號棄用,比過ldap就好
						//->where('student_birthday',$request->get('student_birthday'))//mysql裡的生日棄用,比過ldap就好
						->first();

						$askApply = false;

						if($stuParentData) {
							//如父母有身分證就要再多核對
							if($stuParentData->parent_idno!='') {
								if($stuParentData->parent_idno != $userNow->idno) {
									//return redirect()->back()->with("error","您帳號的身分證號與學生的監護人資料不符，請確認後再行綁定，謝謝！")->withInput();
									$askApply = true;
								}
							}

							if(!$askApply){
								//進行綁定
								$parentRelation  = new \App\StudentParentRelation();
								$parentRelation->student_idno=$idno;
								//$parentRelation->student_birthday=$request->get('student_birthday');
								$parentRelation->parent_name=$request->get('pname');
								$parentRelation->parent_idno=$userNow->idno;
								$parentRelation->parent_relation=$request->get('relationType');
								$parentRelation->status='1';
								$parentRelation->save();

								//更新student_parent_data
								$stuParentData->status='1';
								$stuParentData->save();	

								return redirect()->route('parents.listConnectChildren')->with("success","家長學生關連綁定成功！");
							}
						} else {
							$askApply = true;
							//return redirect()->back()->with("error","輸入家長資料與學生關連對應不符，請確認後再行綁定，謝謝！")->withInput();
						}

						if($askApply){
							return back()->withInput()->with(['askApply' => 'open', 'pname' => $request->get('pname')
								, 'rtype' => $request->get('relationType'), 'idno' => $idno, 'stdno' => $request->get('student_id')
								, 'birth' => $request->get('student_birthday'), 'dc' => $request->get('dc')]);
						}
					} else {
						return redirect()->back()->with("error","輸入綁定學生資料對應不符，請確認後再行綁定，謝謝！")->withInput();
					}
				} else {
					return redirect()->back()->with("error","輸入綁定學生身分證其身分並非學生，請確認後再行綁定，謝謝！")->withInput();
				}
			} else {
				return redirect()->back()->with("error","該身分證字號查無學生資料(entry not in LDAP)，謝謝！")->withInput();
			}
		} else {
			return redirect()->back()->with("error","該身分證字號不存在於本系統，請確認後再行綁定，謝謝！")->withInput();
		}
	}

	public function showConnectChildrenAuthForm(Request $request)
    {
		$openldap = new LdapServiceProvider();
		if (Auth::check()) {
			$userNow = Auth::user();
		} else return redirect()->back()->with("error","無法取得您的登入資訊，請重新登入，謝謝！");	
		//取家長的學生
		$yearsAgo12=date("Ymd",strtotime("-12 year"));
		$data = StudentParentRelation::where('parent_idno',$userNow->idno)->where('status','1')->orderBy('created_at','desc')->get();
		
		$dataList = [];
		$studentData = [];
		$agreeAll=[];
		$agreeList=[];	
		$student_id=0;	
		if($request->session()->has('student')) $student_id=$request->session()->pull('student');
		if(!empty($request->get('student'))) $student_id=$request->get('student');
		if(!empty($data)) {
			foreach ($data as $d) {
				$cc=[];
				$entry = $openldap->getUserEntry($d['student_idno']);
				if ($entry) {
					//判斷是否為12歲以下
					$studentRow = $openldap->getUserData($entry);
					$cc['student_name']=$studentRow['displayName'];
					//改抓LDAP學生生日進行判斷是否有大於12歲 
					if(substr($studentRow['birthDate'],0,8)>=$yearsAgo12) {						
						$cc['wantAgree']='1';
					} else {
						$cc['wantAgree']='0';
						$cc['student_name']=$cc['student_name'].'(不用授權)';
					}
					$cc['student_idno']=$studentRow['cn'];
					$cc['id']=$d['id'];
				}
				
				if($student_id==$d['id']) {
					 $studentData=$cc;
					 $cc['isChecked']='selected';
				} else {
					$cc['isChecked']='';
				}
				$dataList[$d['id']]=$cc;

			}
			if(empty($studentData)) {
				foreach ($dataList as $dd) {
					 $studentData=$dd;
					 $student_id=$dd['id'];
					 break;
				}
			}

			if($studentData) {
				//取該學生授權資料
				$agreeAll = OauthThirdappStudent::where('parent_idno',$userNow->idno)->where('type','1')->where('student_idno',$studentData['student_idno'])->first();
				$agreeList = OauthThirdappStudent::where('parent_idno',$userNow->idno)->where('type','0')->where('student_idno',$studentData['student_idno'])->get();
			}	
		}
		//取得要12的授權第三方
		$apps=[];
		$thirdappList=Thirdapp::where('authyn','Y')->get();
		if($thirdappList) {
			foreach ($thirdappList as $t) {
				$apps[$t->id]['id'] = $t->id;
				$apps[$t->id]['entry'] = $t->entry;
				$apps[$t->id]['background'] = $t->background;
				$apps[$t->id]['agree']='0';
				if($agreeList) {
					foreach ($agreeList as $a) {
						if($a['thirdapp_id']== $t->id)  $apps[$t->id]['agree']='1';
					}
				}
				$sc = $t->scope;
				$apps[$t->id]['scope_list']='';
				if(is_string ($sc) && $sc != ''){
					$ss = explode(" ", $sc);
					$scopes=DB::table('oauth_scope_field')->select('field_cname')->distinct()->whereIn('scope', $ss)->get();
					foreach ($scopes as $s) {
						if($apps[$t->id]['scope_list']!='') $apps[$t->id]['scope_list']=$apps[$t->id]['scope_list'] . ", ";
						$apps[$t->id]['scope_list']=$apps[$t->id]['scope_list'] . $s->field_cname;
					}
				} else  {
					$apps[$t->id]['scope_list']="";
				}				
			}
		}
		return view('parents.connectchildrenauth', ['dataList' => $dataList,'apps' => $apps, 'agreeList' => $agreeList, 'agreeAll' => $agreeAll,'student' => $studentData]);		
	}
	
	public function authConnectChild(Request $request)
    {
		if (Auth::check()) {
			$userNow = Auth::user();
		} else return redirect()->back()->with("error","無法取得您的登入資訊，請重新登入，謝謝！");	
		if($request->get('student')=='') {
			return redirect()->back()->with("error","無法進行更新，可能您選擇的是非12歲以下學生，謝謝！")->withInput();
		} 

		$studentData = StudentParentRelation::where('id',$request->get('student'))->first();
		$res=OauthThirdappStudent::where('student_idno',$studentData->student_idno)->where('parent_idno',$userNow->idno)->delete();
		if($request->get('agreeAll')=='1') {
			$obj  = new \App\OauthThirdappStudent();
			$obj->student_idno=$studentData->student_idno;
			$obj->parent_idno=$userNow->idno;
			$obj->type='1';
			$obj->save();
		} else  {
			$agreeList = $request->get('agree');
			if(!empty($agreeList)) {
				foreach($agreeList as $a){
					if($a!='') {
						$obj  = new \App\OauthThirdappStudent();
						$obj->student_idno=$studentData->student_idno;
						$obj->parent_idno=$userNow->idno;
						$obj->type='0';
						$obj->thirdapp_id=$a;
						$obj->save();
					}	
				}
			}
		}
		
		return redirect()->route('parents.showConnectChildrenAuthForm')->with("success","授權更新成功！")->with("student",$request->get('student'));
	}		

	public function connectChildQRcode(Request $request) {
	  if (Auth::check()) {
			$userNow = Auth::user();
	  } else redirect()->route('/')->with("error","無法取得您的登入資訊，請重新登入，謝謝！");

	  $openldap = new LdapServiceProvider();
	  $qrcodeData = $request->session()->pull('qrcodeObject'); //StudentParentsQrcode
	  //用姓名 座號 位置 於LDAP找學生
	  $students = $openldap->findUsers("(&(displayName=$qrcodeData->std_name)(tpSeat=$qrcodeData->std_seat)(employeeType=學生)(tpClass=$qrcodeData->std_cls))", ["entryUUID","inetUserStatus","uid","cn","displayName","tpClass","tpSeat","o","birthDate"]);
	  if($students) {
		foreach ($students as $stu) {
			//用stu idno id  + 父名找關連
			$stuParentData = StudentParentData::where('parent_name',$qrcodeData->par_name)
			->where('parent_relation',$qrcodeData->par_rel)
			->where('student_idno',$stu['cn'])
			->where('id',$qrcodeData->dataid)
			//->where('student_birthday',substr($stu['birthDate'],0,8))
			->first();

			//綁定 家長ID check
			if($stuParentData) {
				if($stuParentData->status=='0') {
					//如父母有身分證就要再多核對						
					if($stuParentData->parent_idno!='') {
						if($stuParentData->parent_idno != $userNow->idno) {
							return redirect()->route('parents.listConnectChildren')->with("error","您帳號的身分證號與學生的監護人資料不符，請確認後再行綁定，謝謝！");
						}
					}	
					//進行綁定
					$parentRelation  = new \App\StudentParentRelation();
					$parentRelation->student_idno=$stu['cn'];
					//$parentRelation->student_birthday = substr($stu['birthDate'],0,8);
					$parentRelation->parent_name=$qrcodeData->par_name;
					$parentRelation->parent_idno=$userNow->idno;
					$parentRelation->parent_relation=$qrcodeData->par_rel;
					$parentRelation->status='1';
					$parentRelation->save();

					//更新student_parent_data
					$stuParentData->status='1';
					$stuParentData->save();	
					return redirect()->route('parents.listConnectChildren')->with("success","家長學生關連綁定成功！");
				} else {
					return redirect()->route('parents.listConnectChildren')->with("error","該筆家長學生關連資料已綁定過，謝謝！");
				}
			} else {
				return redirect()->route('parents.listConnectChildren')->with("error","綁定學生家長對應資料不符，請與學校確認後再行綁定，謝謝！");
			}
			break;
		}
	  } else {
		return redirect()->route('parents.listConnectChildren')->with("error","該學生資料不存在於本系統，請與學校確認後再行綁定，謝謝！");
	  }
	}

	public function connectApply(Request $request)
	{
		$idno = $request->get('idno');
		$dc = $request->get('dc');
		$stdno = $request->get('stdno');
		$birth = $request->get('birth');
		$pidno = Auth::user()->idno;
		$rtype = $request->get('rtype');
		$pname = $request->get('pname');
		$email = $request->get('email');
		$mobile = $request->get('mobile');

		$openldap = new LdapServiceProvider();
		$schid = $openldap->getOrgID($request->get('dc'));
		$chkidno = new idno();

		$entry = $openldap->getUserEntry($idno);
		if($entry)
			$st = $openldap->getUserData($entry);

		if(!isset($st) || empty($st) || ((is_array($st['employeeType']) && !in_array('學生',$st['employeeType'])) || (is_string($st['employeeType']) && $st['employeeType'] != '學生')) || $st['employeeNumber'] != $stdno || substr($st['birthDate'],0,8) != $birth || $st['o'] != $dc || empty($schid))
			return '{"error":"學生資料錯誤"}';

		if(!$chkidno->passes(null,$pidno) || ($rtype != '父親' && $rtype != '母親' && $rtype != '監護人') || empty($pname))
			return '{"error":"家長資料錯誤"}';

		$data = [];
		if(empty($email)){
			$data['email'] = 'eMail信箱為必填！';
		}else if(count(explode('@',$email)) != 2 || strlen($email) < 8 || substr($email,-1) == '.' || count(explode('.',explode('@',$email)[1])) < 2){
			$data['email'] = 'eMail信箱格式不正確！';
		}

		if(empty($mobile)){
			$data['mobile'] = '電話號碼為必填！';
		}

		if(count($data) > 0)
			return json_encode($data, JSON_UNESCAPED_UNICODE);

		$sp = StudentParentRelation::where('student_idno',$idno)->where('parent_idno',$pidno)->get();
		if(count($sp) > 0)
			return '{"error":"輸入的身分證字號已建立過親子連結"}';

		//是否資料在DB有
		$stuParentData = StudentParentData::where('parent_name',$pname)
			->where('school_id',$schid)
			->where('student_idno',$idno)
			//->where('student_id',$stdno)//mysql裡的學號棄用,比過ldap就好
			//->where('student_birthday',$birth)//mysql裡的生日棄用,比過ldap就好
			->first();

		$askApply = false;

		if($stuParentData) {
			//如父母有身分證就要再多核對
			if($stuParentData->parent_idno != '' && $stuParentData->parent_idno != $userNow->idno)
				$askApply = true;
		} else {
			$apply = \App\StudentParentApply::where('student_idno',$idno)->where('parent_idno',$pidno)->where('status','0')->first();
			if(!empty($apply))
				return '{"error":"已有待審核的申請存在，不可以重複送出申請"}';
			$askApply = true;
		}

		if($askApply){
			/*
			$entry = $openldap->getUserEntry($pidno);
			$pdata = $openldap->getUserData($entry);

			$accounts = [];
			if (is_array($pdata['uid'])) $accounts = $pdata['uid'];
			else $accounts[] = $pdata['uid'];
			$emailLogin = array_key_exists('mail',$pdata) && in_array($pdata['mail'],$accounts);
			$mobileLogin = array_key_exists('mobile',$pdata) && in_array($pdata['mobile'],$accounts);
			*/
			$user = Auth::user();

			//$accounts = $openldap->getUserAccounts($pidno);
			/*
			$userinfo = array();

			if(!array_key_exists('mail',$pdata)){
				$userinfo['mail'] = $email;
				$user->email = $email;
			}else if ($pdata['mail'] != $email){
				if(!$openldap->emailAvailable($pidno, $email))
					return '{"error":"您輸入的電子郵件已經被別人使用！"}';
				$userinfo['mail'] = $email;
				$user->email = $email;
			}

			if(!array_key_exists('mobile',$pdata)){
				$userinfo['mobile'] = $mobile;
				$user->mobile = $mobile;
			}else if ($pdata['mobile'] != $mobile){
				if(!$openldap->mobileAvailable($pidno, $mobile))
					return '{"error":"您輸入的手機號碼已經被別人使用！"}';
				$userinfo['mobile'] = $mobile;
				$user->mobile = $mobile;
			}
			*/

			if(!$openldap->emailAvailable($pidno, $email))
				return '{"error":"您輸入的電子郵件已經被別人使用！"}';
			if(!$openldap->mobileAvailable($pidno, $mobile))
				return '{"error":"您輸入的手機號碼已經被別人使用！"}';

			if($user->email != $email || $user->mobile != $mobile){
				$user->email = $email;
				$user->mobile = $mobile;

				$info = \App\ParentsInfo::where('cn',$pidno)->first();
				if($info){
					$info->mail = $email;
					$info->mobile = $mobile;
				}else{
					return '{"error":"找不到個人資料！"}';
				}
			}

			$a = new \App\StudentParentApply();
			$a->school_id = $schid;
			$a->student_idno = $idno;
			$a->parent_idno = $pidno;
			$a->parent_relation = $rtype;
			$a->parent_name = $pname;
			$a->parent_email = $email;
			$a->parent_mobile = $mobile;
			$a->save();

			$message = '已送出申請資料，請等待導師審核';

			if(isset($info)){
				$user->save();
				$info->save();
			}

			$filter = '(&(o='.$dc.')(tpTutorClass='.$st['tpClass'].'))';
			$tutor = $openldap->findUsers($filter, ["displayName","mail"]);

			if(!empty($tutor)){
				$m = $tutor[0]['mail'];

				if(empty($m)){
					$message .= '<br/>學生的導師未設定email，無法發送通知';
				}else if(!filter_var($m, FILTER_VALIDATE_EMAIL)){
					$message .= '<br/>學生的導師email設定錯誤，無法發送通知';
				}else{
					try{
						$text = $tutor[0]['displayName'].' 老師您好'.PHP_EOL.PHP_EOL.'您班上學生 '.$st['displayName'].' 的'.$rtype.'於 '.Carbon::now()->format('Y-m-d H:i:s').' 送出了一份建立親子連結的申請'.PHP_EOL.'請您抽空登入<臺北市教育人員單一身分驗證服務>審核';
						\Mail::raw($text, function($message) use ($m)
						{
							$message->from(env('MAIL_USERNAME', ''), '臺北市教育人員單一身分驗證服務');
							$message->to($m)->subject('臺北市教育人員單一身分驗證服務-學生家長建立親子連結申請');
						});

						$message .= '<br/>已發送申請通知mail給學生的導師';
					}catch (\Exception $e){
						$message .= '<br/>發送申請通知mail給學生的導師時發生錯誤';
					}
				}
			}else{
				$message .= '<br/>找不到學生的導師，無法發送通知';
			}

			\Session::flash('success', $message);
			return '{"success":"'.$message.'"}';
		}else{
			return '{"error":"輸入的家長資料錯誤，請重新操作親子連結服務功能"}';
		}

		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	public function gsuitepage()
	{
		$user = User::where('uuid',Auth::user()->uuid)->first();
		$date = '';

		if($user && !empty($user->gsuite_created_at))
			$date = $user->gsuite_created_at;

		return view('admin.personalgsuitepage', ['date' => $date, 'domain' => config('saml.email_domain')]);
	}

	public function gsuiteregister()
	{
		$user = User::where('uuid',Auth::user()->uuid)->first();

		if(!empty($user)){
			if(empty($user->gsuite_created_at)){
				$openldap = new LdapServiceProvider();
				$gs = new GoogleServiceProvider();
				$domain = config('saml.email_domain');

				$a = $openldap->findAccounts('cn='.Auth::user()->idno, ['uid']);
				$email = $a[0]['uid'].'@'.$domain;

				if(empty($a))
					return back()->withInput()->with("error","找不到帳號資料！");

				try{
					$result = $gs->queryUserByEmail($email);
				}catch (\Exception $e){
				}

				if(!isset($result) || empty($result) || !is_array($result) || count($result) < 1){
					$flag = false;

					try{
						$data = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));

						$result = $gs->createUserAccount($a[0]['uid'], $data['sn'], $data['givenName'], $data['displayName'], $data['cn'].'1qaz@WSX');

						if(Auth::user()->inRole('教師')){
							$member = $gs->groupAddMembers('teachers@'.$domain, [$email]);
							//Log::debug('Add Teacher Member:'.json_encode($member,JSON_UNESCAPED_UNICODE));
						}
						if(Auth::user()->inRole('學生')){
							$member = $gs->groupAddMembers('students@'.$domain, [$email]);
							//Log::debug('Add Student Member:'.json_encode($member,JSON_UNESCAPED_UNICODE));
						}

						$flag = !empty($result) && array_key_exists('primaryEmail', $result) && $result->primaryEmail == $email;
					}catch (\Exception $e){
						//$error = $e->getMessage();
						Log::debug('Register G-Suite Account failed:'.$e->getMessage());
					}

					if(!$flag){
						if(isset($error))
							return back()->withInput()->with("error","註冊帳號失敗:".$error);
						return back()->withInput()->with("error","註冊帳號失敗！");
					}
				}else{
					return back()->withInput()->with("error","已有相同的G-Suite帳號存在，無法新增！");
				}

				$user->gsuite_created_at = Carbon::now();
				$user->gsuite_email = $email;
				$user->save();

				return back()->withInput()->with("success","註冊成功！");
			}else{
				return back()->withInput()->with("error","您已經註冊過了！");
			}
		}else{
			return back()->withInput()->with("error","找不到使用者！");
		}
	}

	public function parentslinkVerifyForm(Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$kind = $request->get('kind');
		if($kind != 'all') $kind = 'now';

		$title = null;
		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));

		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;
		$dc = array_key_exists('o',$user) ? $user['o']:null;

		$data = [];
		if(!empty($cls) && !empty($dc)){
			$title = $openldap->getOuTitle($dc,$cls);

			if ($title) {
				$students = $openldap->findUsers("(&(o=$dc)(employeeType=學生)(tpClass=$cls))", ["entryUUID","uid","cn","displayName","tpClass","tpSeat","o","employeeNumber"]);

				$idnos = [];
				if($students){
					foreach($students as $st)
						$idnos[$st['cn']] = $st;
				}
				unset($students);

				if(count($idnos) > 0){
					$data = \App\StudentParentApply::whereIn('student_idno',array_keys($idnos));
					if($kind != 'all'){
						$data->where('status','0');
					}else{
						$data->where('status', '<>', '0');
					}
					$data = $data->get();

					for($i=0;$i<count($data);$i++){
						$data[$i]['row'] = $i+1;
						$idno = $data[$i]->student_idno;
						if(array_key_exists($idno,$idnos)){
							$data[$i]['seat'] = $idnos[$idno]['tpSeat'];
							$data[$i]['name'] = $idnos[$idno]['displayName'];
							$data[$i]['employeeNumber'] = $idnos[$idno]['employeeNumber'];
						}

						if($data[$i]->student_idno != '0')
							$data[$i]['result'] = ($data[$i]['status']=='1'?'同意':'不同意').(empty($data[$i]['cause'])?'':(':'.$data[$i]['cause']));
					}
				}
			}
		}

		return view('admin.personalparentslinkverify', ['data' => $data, 'clsname' => $title, 'kind' => $kind]);
	}

	public function parentslinkVerify(Request $request)
	{
		if(!Auth::user()->inRole('教師'))
			return redirect()->route('/');

		$openldap = new LdapServiceProvider();
		$user = $openldap->getUserData($openldap->getUserEntry(Auth::user()->uuid));
		$dc = array_key_exists('o',$user) ? $user['o']:null;
		$cls = array_key_exists('tpTutorClass',$user) ? $user['tpTutorClass']:null;

		if(empty($dc))
			return '{"error":"找不到所屬學校"}';
		if(empty($cls))
			return '{"error":"您不是班級導師"}';

		$title = $openldap->getOuTitle($dc,$cls);

		if(!$title)
			return '{"error":"找不到班級資料"}';

		$id = $request->get('id');
		if(!empty($id)){
			$id = explode(",",$id);
			if(is_array($id) && count($id)){
				foreach($id as $d){
					if(!ctype_digit($d))
						return '{"error":"系統編號錯誤"}';
				}
			}else{
				return '{"error":"缺少系統編號"}';
			}
		}

		$apply = $request->get('apply');
		//$cause = $request->get('cause');

		if(!is_string($apply) || ($apply != '1' && $apply != '2'))
			return '{"error":"請選擇同意或不同意"}';
		//if(!is_string($cause))
		//	$cause = null;

		$sps = \App\StudentParentApply::whereIn('id',$id)->get();

		if(empty($sps))
			return '{"error":"找不到申請資料"}';

		foreach($sps as $sp){
			$student = $openldap->getUserData($openldap->getUserEntry($sp->student_idno));

			if(empty($student))
				return '{"error":"找不到學生"}';
			if(!array_key_exists('tpClass',$student))
				return '{"error":"找不到學生班級"}';
			if(!$student['tpClass'] || $cls != $student['tpClass'])
				return '{"error":"您不是這位學生的導師"}';
			if($sp->status != '0')
				return '{"error":"這筆申請已經審核過了"}';

			if($apply == '1'){
				//取得學生已連結的家長
				$pars = StudentParentRelation::where('student_idno',$sp->student_idno)
					->where('parent_idno',$sp->parent_idno)
					//->where('parent_name',$sp->parent_name)
					->first();

				if(!empty($pars))
					return '{"error":"已有相同家長資料的親子連結存在，無法建立"}';
			}
		}

		$mailto = [];
		$check = [];

		foreach($sps as $sp){
			$sp->status = $apply;
			//$sp->cause = $cause;
			$sp->verify_tm = Carbon::now();
			$sp->save();

			if($apply == '1' && !in_array($sp->student_idno.'-'.$sp->parent_idno,$check)){
				$pars = new StudentParentRelation();
				$pars->student_idno = $sp->student_idno;
				//$pars->student_birthday = $sp->student_birthday;
				$pars->parent_idno = $sp->parent_idno;
				$pars->parent_name = $sp->parent_name;
				$pars->parent_relation = $sp->parent_relation;
				$pars->status = '1';
				$pars->save();

				$mailto[] = ['text' => $pars->parent_name.'您好'.PHP_EOL.PHP_EOL.'您在<臺北市教育人員單一身分驗證服務>送出，要與'.$student['displayName'].'建立親子連結關係的申請已於'.date('Y-m-d H:i:s', strtotime($sp->verify_tm)).'通過了', 'mail' => $sp->parent_email];
				$check[] = $sp->student_idno.'-'.$sp->parent_idno;
			}
		}

		foreach($mailto as $mt){
			try{
				\Mail::raw($mt['text'], function($message) use ($mt)
				{
					$message->from(Config::get('mail.from.address'), Config::get('mail.from.name'));
					$message->to($mt['mail'])->subject('臺北市教育人員單一身分驗證服務-建立親子連結審核通知');
				});
			}catch (\Exception $e){
				Log::info('parentslinkVerify send mail to '.$mt['mail'].' error:'.$e->getMessage());
			}
		}

		$text = ($apply=='1'?'同意':'不同意');
		\Session::flash('success', '審核成功');

		return '{"success":"'.$text.'"}';
	}
}