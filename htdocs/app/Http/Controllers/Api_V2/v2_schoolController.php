<?php

namespace App\Http\Controllers\Api_V2;

use Log;
use Auth;
use Config;
use App\Providers\LdapServiceProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class v2_schoolController extends Controller
{
    public function all(Request $request)
    {
        $openldap = new LdapServiceProvider();
        $condition[] = "(objectClass=tpeduSchool)";
        $area = $request->get('area');
        if ($area) $condition[] = "(st=$area)";
        $name = $request->get('name');
        if ($name) $condition[] = "(description=*$name*)";
        $sid = $request->get('sid');
        if ($sid) $condition[] = "(tpUniformNumbers=$sid)";
        if (count($condition) > 1) {
            $filter = '(&';
            foreach ($condition as $c) {
                $filter .= $c;
            }
            $filter .= ')';
        } else {
            $filter = $condition[0];
        }
        $json = $openldap->getOrgs($filter);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '找不到符合條件的機關學校'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function one(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
        $entry = $openldap->getOrgEntry($dc);
        if (!$entry) return response()->json([ 'error' => '找不到指定的機關學校'], 404, JSON_UNESCAPED_UNICODE);
		$json = $openldap->getOrgData($entry);
        unset($json['tpAdministrator']);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '找不到指定的機關學校'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allTeachersByOrg(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
        $filter = "(&(o=$dc)(!(employeeType=學生))(inetUserStatus=active))";
        $teachers = $openldap->findUsers($filter, "entryUUID");
		$json = array();
		foreach ($teachers as $teacher) {
	    	$json[] = $teacher['entryUUID'];
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該機關學校查無教職員'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function peopleSearch(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
        $condition[] = "(o=$dc)";
        $role = $request->get('role');
        if ($role) $condition[] = "(employeeType=$role)";
        $idno = $request->get('idno');
        if ($idno) $condition[] = "(cn=$idno)";
        $uid = $request->get('uid');
        if ($uid) $condition[] = "(uid=$uid)";
        $sysid = $request->get('sysid');
        if ($sysid) $condition[] = "(employeeNumber=$sysid)";
        $gender = $request->get('gender');
        if ($gender) $condition[] = "(gender=$gender)";
        $name = $request->get('name');
        if ($name) $condition[] = "(displayName=*$name*)";
        $email = $request->get('email');
        if ($email) $condition[] = "(mail=*$email*)";
        $tel = $request->get('tel');
        if ($tel) $condition[] = "(|(mobile=$tel)(telephoneNumber=$tel))";
        if (count($condition) > 1) {
            $filter = '(&';
            foreach ($condition as $c) {
                $filter .= $c;
            }
            $filter .= '(inetUserStatus=active))';
            $people = $openldap->findUsers($filter, "entryUUID");
            $json = array();
            if ($people)
	    	    foreach ($people as $one) {
	        	    $json[] = $one['entryUUID'];
		        }
            if ($json)
                return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
            else
                return response()->json([ 'error' => "找不到符合條件的人員"], 404, JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json([ 'error' => '請提供搜尋條件'], 500, JSON_UNESCAPED_UNICODE);
        }
    }

    public function allOu(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
		$json = $openldap->getOus($dc, "行政部門");
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該機關學校查無行政部門'], 404, JSON_UNESCAPED_UNICODE);
   }

    public function oneOu(Request $request, $dc, $ou_id)
    {
		$openldap = new LdapServiceProvider();
        $entry = $openldap->getOuEntry($dc,$ou_id);
        if (!$entry) return response()->json([ 'error' => '找不到指定的行政部門'], 404, JSON_UNESCAPED_UNICODE);
		$json = $openldap->getOuData($entry);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '找不到指定的行政部門'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allTeachersByUnit(Request $request, $dc, $ou_id)
    {
		$json = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(ou=*$ou_id)(inetUserStatus=active))", "entryUUID");
		foreach ($teachers as $teacher) {
	    	$json[] = $teacher['entryUUID'];
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該行政部門查無在職人員'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allSubject(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
		$json = $openldap->getSubjects($dc);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該機關學校查無教學科目'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function oneSubject(Request $request, $dc, $subj_id)
    {
		$openldap = new LdapServiceProvider();
        $entry = $openldap->getSubjectEntry($dc,$subj_id);
        if (!$entry) return response()->json([ 'error' => '找不到指定的教學科目'], 404, JSON_UNESCAPED_UNICODE);
		$json = $openldap->getSubjectData($entry);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '找不到指定的教學科目'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allTeachersBySubject(Request $request, $dc, $subj_id)
    {
		$json = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(tpTeachClass=*$subj_id)(inetUserStatus=active))", "entryUUID");
		foreach ($teachers as $teacher) {
	    	$json[] = $teacher['entryUUID'];
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該教學科目查無任教教師'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allClassesBySubject(Request $request, $dc, $subj_id)
    {
        $json = array();
        $classes = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(tpTeachClass=*$subj_id))", ["o", "tpTeachClass"]);
		foreach ($teachers as $teacher) {
            if (is_array($teacher['tpTeachClass'])) {
                $classes = $teacher['tpTeachClass'];
            } else {
                $classes[] = $teacher['tpTeachClass'];
            }
            foreach ($classes as $class) {
                $a = explode(',', $class);
                if (count($a) == 3 && $a[0] == $dc && $a[2] == $subj_id && !in_array($a[1], $json)) $json[] = $a[1];
                if (count($a) == 2 && $a[1] == $subj_id && !in_array($a[0], $json)) $json[] = $a[0];
            }
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該教學科目查無授課班級'], 404, JSON_UNESCAPED_UNICODE);
   }

    public function allRole(Request $request, $dc, $ou_id)
    {
		$openldap = new LdapServiceProvider();
		$json = $openldap->getRoles($dc,$ou_id);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該行政部門查無職務資訊'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function oneRole(Request $request, $dc, $ou_id, $role_id)
    {
		$openldap = new LdapServiceProvider();
        $entry = $openldap->getRoleEntry($dc,$ou_id,$role_id);
        if (!$entry) return response()->json([ 'error' => '找不到指定的行政職務'], 404, JSON_UNESCAPED_UNICODE);
		$json = $openldap->getRoleData($entry);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '找不到指定的行政職務'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allTeachersByRole(Request $request, $dc, $ou_id, $role_id)
    {
		$json = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(ou=*$ou_id)(title=*$role_id)(inetUserStatus=active))", "entryUUID");
		foreach ($teachers as $teacher) {
	    	$json[] = $teacher['entryUUID'];
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該行政職務查無在職人員'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allClass(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
		$json = $openldap->getOus($dc, "教學班級");
        foreach (array_keys($json) as $key) {
            unset($json[$key]->teacher);
        }
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該機關學校查無班級'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function oneClass(Request $request, $dc, $class_id)
    {
		$openldap = new LdapServiceProvider();
        $entry = $openldap->getOuEntry($dc,$class_id);
        if (!$entry) return response()->json([ 'error' => '找不到指定的班級'], 404, JSON_UNESCAPED_UNICODE);
		$json = $openldap->getOuData($entry);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '找不到指定的班級'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allTeachersByClass(Request $request, $dc, $class_id)
    {
		$json = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(tpTeachClass=*$class_id*)(inetUserStatus=active))", "entryUUID");
		foreach ($teachers as $teacher) {
	    	$json[] = $teacher['entryUUID'];
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該班級查無任教老師'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allStudentsByClass(Request $request, $dc, $class_id)
    {
		$json = array();
		$openldap = new LdapServiceProvider();
		$students = $openldap->findUsers("(&(o=$dc)(tpClass=$class_id)(inetUserStatus=active))", "entryUUID");
		foreach ($students as $student) {
	    	$json[] = $student['entryUUID'];
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該班級查無學生'], 404, JSON_UNESCAPED_UNICODE);
    }

    public function allSubjectsByClass(Request $request, $dc, $class_id)
    {
        $json = array();
        $classes = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(tpTeachClass=*,$class_id,*))", ["o", "tpTeachClass"]);
		foreach ($teachers as $teacher) {
            if (is_array($teacher['tpTeachClass'])) {
                $classes = $teacher['tpTeachClass'];
            } else {
                $classes[] = $teacher['tpTeachClass'];
            }
            foreach ($classes as $class) {
                $a = explode(',', $class);
                if (count($a) == 3 && $a[0] == $dc && $a[1] == $class_id && !in_array($a[2], $json)) $json[] = $a[2];
                if (count($a) == 2 && $a[0] == $class_id && !in_array($a[1], $json)) $json[] = $a[1];
            }
		}
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '該班級查無教學科目'], 404, JSON_UNESCAPED_UNICODE);
    }

    // below function for schoolAdmin scope.
    public function schoolUpdate(Request $request, $dc)
    {
        $openldap = new LdapServiceProvider();
        $user = $request->user();
        $entry = $openldap->getOrgEntry($dc);
        $data = $openldap->getOrgData($entry, 'tpAdministrator');
        if (is_array($data['tpAdministrator'])) {
            if (!in_array($user->idno, $data['tpAdministrator']))
                return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        } elseif ($user->idno != $data['tpAdministrator']) {
            return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        }

        $schoolinfo = array();
        if (!empty($request->get('area'))) $schoolinfo['st'] = $request->get('area');
		if (!empty($request->get('name'))) $schoolinfo['description'] = $request->get('name');
		if (!empty($request->get('fax'))) $schoolinfo['facsimileTelephoneNumber'] = $request->get('fax');
		if (!empty($request->get('tel'))) $schoolinfo['telephoneNumber'] = $request->get('tel');
		if (!empty($request->get('postal'))) $schoolinfo['postalCode'] = $request->get('postal');
		if (!empty($request->get('address'))) $schoolinfo['street'] = $request->get('address');
		if (!empty($request->get('mbox'))) $schoolinfo['postOfficeBox'] = $request->get('mbox');
		if (!empty($request->get('www'))) $schoolinfo['wWWHomePage'] = $request->get('www');
		if (!empty($request->get('uno'))) $schoolinfo['tpUniformNumbers'] = $request->get('uno');
		if (!empty($request->get('ipv4'))) $schoolinfo['tpIpv4'] = $request->get('ipv4');
		if (!empty($request->get('ipv6'))) $schoolinfo['tpIpv6'] = $request->get('ipv6');
		if (!empty($request->get('admins'))) $schoolinfo['tpAdministrator'] = $request->get('admins');
		$openldap->updateOus($dc, $request->get('ous'));
		$openldap->updateClasses($dc, $request->get('classes'));
		$openldap->updateSubjects($dc, $request->get('subjects'));
		$openldap->updateData($entry, $schoolinfo);
		$entry = $openldap->getOrgEntry($dc);
		$json = $openldap->getOrgData($entry);
		return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
    }

    public function peopleAdd(Request $request, $dc)
    {
        $openldap = new LdapServiceProvider();
        $user = $request->user();
        $entry = $openldap->getOrgEntry($dc);
        $data = $openldap->getOrgData($entry, 'tpAdministrator');
        if (is_array($data['tpAdministrator'])) {
            if (!in_array($user->idno, $data['tpAdministrator']))
                return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        } elseif ($user->idno != $data['tpAdministrator']) {
            return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        }

        $idno = strtoupper($request->get('idno'));
        if (empty($idno)) return response()->json(["error" => "請提供身分證字號"], 400, JSON_UNESCAPED_UNICODE);
        $entry = $openldap->getUserEntry($idno);
        if ($entry) return response()->json(["error" => "該使用者已經存在"], 400, JSON_UNESCAPED_UNICODE);
        if (empty($request->get('type'))) return response()->json(["error" => "請提供該使用者的身份"], 400, JSON_UNESCAPED_UNICODE);
        if (empty($request->get('lastname')) || empty($request->get('firstname'))) return response()->json(["error" => "請提供該使用者的真實姓名"], 400, JSON_UNESCAPED_UNICODE);
		$info = array();
		$info['dn'] = "cn=".$idno.",".Config::get('ldap.userdn');
		$info["objectClass"] = array("tpeduPerson","inetUser");
 		$info["inetUserStatus"] = "Active";
        $info["cn"] = $idno;
	    if (!empty($request->get('password'))) {
            $info["userPassword"] = $openldap->make_ssha_password($request->get('password'));
        } else {
            $info["userPassword"] = $openldap->make_ssha_password(substr($idno, -6));
        }
        $orgs[] = $dc;
        $sch = $request->get('school');
        if (!empty($sch)) {
            if (is_array($sch))
                $orgs = array_unique(array_merge($orgs, $sch));
            else
                $orgs[] = $sch;
        }
        $educloud = array();
		foreach ($orgs as $o) {
			$entry = $openldap->getOrgEntry($o);
			$data = $openldap->getOrgData($entry, 'tpUniformNumbers');
			$sid = $data['tpUniformNumbers'];
			$educloud[] = json_encode(array("sid" => $sid, "role" => $request->get('type')), JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
		}
        $info['o'] = $orgs;
        $info['info'] = $educloud;
		$info['employeeType'] = $request->get('type');
		$info['sn'] = $request->get('lastname');
		$info['givenName'] = $request->get('firstname');
		$info['displayName'] = $info['sn'].$info['givenName'];
        if ($request->get('type') != "學生") {
            if (!empty($request->get('unit'))) $info['ou'] = $request->get('unit');
            if (!empty($request->get('role'))) $info['title'] = $request->get('role');
            if (!empty($request->get('tclass'))) $info['tpTeachClass'] = $request->get('tclass');
        } else {
            if (empty($request->get('stdno'))) return response()->json(["error" => "請提供學號"], 400, JSON_UNESCAPED_UNICODE);
            $info['employeeNumber'] = $request->get('stdno');
            if (empty($request->get('class'))) return response()->json(["error" => "請提供就讀班級"], 400, JSON_UNESCAPED_UNICODE);
            $info['tpClass'] = $request->get('class');
            if (empty($request->get('seat'))) return response()->json(["error" => "請提供學生座號"], 400, JSON_UNESCAPED_UNICODE);
            $info['tpSeat'] = $request->get('seat');
        }
		if (!empty($request->get('memo'))) $info['tpCharacter'] = $request->get('memo');
		if (!empty($request->get('gender'))) $info['gender'] = $request->get('gender');
		if (!empty($request->get('birthdate'))) $info['birthDate'] = $request->get('birthdate')."000000Z";
		if (!empty($request->get('email'))) $info['mail'] = $request->get('email');
		if (!empty($request->get('mobile'))) $info['mobile'] = $request->get('mobile');
		if (!empty($request->get('fax'))) $info['facsimileTelephoneNumber'] = $request->get('fax');
		if (!empty($request->get('otel'))) $info['telephoneNumber'] = $request->get('otel');
		if (!empty($request->get('htel'))) $info['homePhone'] = $request->get('htel');
		if (!empty($request->get('address'))) $info['registeredAddress'] = $request->get('address');
		if (!empty($request->get('conn_address'))) $info['homePostalAddress'] = $request->get('conn_address');
		if (!empty($request->get('www'))) $info['wWWHomePage'] = $request->get('www');
		$openldap->createEntry($info);
		$entry = $openldap->getUserEntry($request->get('idno'));
		$json = $openldap->getUserData($entry);
        if ($json)
            return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
        else
            return response()->json([ 'error' => '人員新增失敗：' . $openldap->error() ], 404, JSON_UNESCAPED_UNICODE);
    }

    public function peopleUpdate(Request $request, $dc, $uuid)
    {
        $openldap = new LdapServiceProvider();
        $user = $request->user();
        $entry = $openldap->getOrgEntry($dc);
        $data = $openldap->getOrgData($entry, 'tpAdministrator');
        if (is_array($data['tpAdministrator'])) {
            if (!in_array($user->idno, $data['tpAdministrator']))
                return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        } elseif ($user->idno != $data['tpAdministrator']) {
            return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        }

        $entry = $openldap->getUserEntry($uuid);
        $person = $openldap->getUserData($entry);
        $orgs = array();
        if (is_array($person['o'])) {
            $orgs = $person['o'];
        } else {
            $orgs[] = $person['o'];
        }
        if (!in_array($dc, $orgs)) return response()->json([ 'error' => '找不到指定的人員'], 404, JSON_UNESCAPED_UNICODE);

        $info = array();
        if (!empty($request->get('lastname'))) $info['sn'] = $request->get('lastname');
		if (!empty($request->get('firstname'))) $info['givenName'] = $request->get('firstname');
		if (!empty($request->get('lastname')) && !empty($request->get('firstname'))) $info['displayName'] = $info['sn'].$info['givenName'];
        if ($person['employeeType'] != "學生") {
            if (!empty($request->get('unit'))) {
                $ous = array();
                $units = array();
                if (isset($person['ou'])) {
                    if (is_array($person['ou'])) {
                        $ous = $person['ou'];
                    } else {
                        $ous[] = $person['ou'];
                    }
                    foreach ($ous as $ou_pair) {
                        $a = explode(',', $ou_pair);
                        if (count($a) == 2 && $a[0] != $dc) $units[] = $ou_pair;
                    }
                }
                if (is_array($request->get('unit'))) {
                    $units = array_values($units + $request->get('unit'));
                } else {
                    $units[] = $request->get('unit');
                }
                $info['ou'] = $units;
            }
            if (!empty($request->get('role'))) {
                $titles = array();
                $roles = array();
                if (isset($person['title'])) {
                    if (is_array($person['title'])) {
                        $titles = $person['title'];
                    } else {
                        $titles[] = $person['title'];
                    }
                    foreach ($titles as $title_pair) {
                        $a = explode(',', $title_pair);
                        if (count($a) == 3 && $a[0] != $dc) $roles[] = $title_pair;
                    }
                }
                if (is_array($request->get('role'))) {
                    $roles = array_values($roles + $request->get('role'));
                } else {
                    $roles[] = $request->get('role');
                }
                $info['title'] = $roles;
            }
            if (!empty($request->get('tclass'))) {
                $assign = array();
                if (isset($person['tpTeachClass'])) {
                    if (is_array($person['tpTeachClass'])) {
                        $tclass = $person['tpTeachClass'];
                    } else {
                        $tclass[] = $person['tpTeachClass'];
                    }
                    foreach ($tclass as $pair) {
                        $a = explode(',', $pair);
                        if (count($a) == 3 && $a[0] != $dc) $assign[] = $pair;
                    }
                }
                if (is_array($request->get('tclass'))) {
                    $assign = array_values($assign + $request->get('tclass'));
                } else {
                    $assign[] = $request->get('tclass');
                }
                $info['tpTeachClass'] = $assign;
            }
        } else {
            if (!empty($request->get('stdno'))) $info['employeeNumber'] = $request->get('stdno');
            if (!empty($request->get('class'))) $info['tpClass'] = $request->get('class');
            if (!empty($request->get('seat'))) $info['tpSeat'] = $request->get('seat');
        }
		if (!empty($request->get('memo'))) $info['tpCharacter'] = $request->get('memo');
		if (!empty($request->get('gender'))) $info['gender'] = $request->get('gender');
		if (!empty($request->get('birthdate'))) $info['birthDate'] = $request->get('birthdate')."000000Z";
		if (!empty($request->get('email'))) $info['mail'] = $request->get('email');
		if (!empty($request->get('mobile'))) $info['mobile'] = $request->get('mobile');
		if (!empty($request->get('fax'))) $info['facsimileTelephoneNumber'] = $request->get('fax');
		if (!empty($request->get('otel'))) $info['telephoneNumber'] = $request->get('otel');
		if (!empty($request->get('htel'))) $info['homePhone'] = $request->get('htel');
		if (!empty($request->get('address'))) $info['registeredAddress'] = $request->get('address');
		if (!empty($request->get('conn_address'))) $info['homePostalAddress'] = $request->get('conn_address');
		if (!empty($request->get('www'))) $info['wWWHomePage'] = $request->get('www');
		$openldap->updateData($entry, $info);
        if (!empty($request->get('idno'))) {
            $idno = $request->get('idno');
            if ($person['cn'] != $idno) {
                $result = $openldap->renameUser($person['cn'], $idno);
                if ($result) {
                    $model = new \App\User();
                    $user = $model->newQuery()
                    ->where('idno', $person['cn'])
                    ->first();
                    if ($user) $user->delete();
                }
            }
        }
        $entry = $openldap->getUserEntry($uuid);
		$json = $openldap->getUserData($entry);
		return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
    }

    public function peopleRemove(Request $request, $dc, $uuid)
    {
        $openldap = new LdapServiceProvider();
        $user = $request->user();
        $entry = $openldap->getOrgEntry($dc);
        $data = $openldap->getOrgData($entry, 'tpAdministrator');
        if (is_array($data['tpAdministrator'])) {
            if (!in_array($user->idno, $data['tpAdministrator']))
                return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        } elseif ($user->idno != $data['tpAdministrator']) {
            return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        }

        $entry = $openldap->getUserEntry($uuid);
        $person = $openldap->getUserData($entry);
        $orgs = array();
        if (is_array($person['o'])) {
            $orgs = $person['o'];
        } else {
            $orgs[] = $person['o'];
        }
        if (!in_array($dc, $orgs)) return response()->json([ 'error' => '找不到指定的人員'], 404, JSON_UNESCAPED_UNICODE);

        $result = $openldap->updateData($entry,  [ 'inetUserStatus' => 'deleted' ]);
//		$result = $openldap->deleteEntry($entry);
		if ($result)
		    return response()->json([ 'success' => '指定的人員已經刪除'], 410, JSON_UNESCAPED_UNICODE);
		else
		    return response()->json([ 'error' => '指定的人員刪除失敗：' . $openldap->error() ], 500, JSON_UNESCAPED_UNICODE);
    }
  
    public function people(Request $request, $dc, $uuid)
    {
        $openldap = new LdapServiceProvider();
        $user = $request->user();
        $entry = $openldap->getOrgEntry($dc);
        $data = $openldap->getOrgData($entry, 'tpAdministrator');
        if (is_array($data['tpAdministrator'])) {
            if (!in_array($user->idno, $data['tpAdministrator']))
                return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        } elseif ($user->idno != $data['tpAdministrator']) {
            return response()->json(["error" => "你未被權限管理此機關學校"], 403, JSON_UNESCAPED_UNICODE);
        }

        $entry = $openldap->getUserEntry($uuid);
        $person = $openldap->getUserData($entry);
        $orgs = array();
        if (!array_key_exists('o',$person)) {
            return response()->json([ 'error' => '找不到指定的人員'], 404, JSON_UNESCAPED_UNICODE);
        } else {
            if (is_array($person['o'])) {
                $orgs = $person['o'];
            } else {
                $orgs[] = $person['o'];
            }
            if (!in_array($dc, $orgs)) return response()->json([ 'error' => '找不到指定的人員'], 404, JSON_UNESCAPED_UNICODE);
        }

        return response()->json($person, 200, JSON_UNESCAPED_UNICODE);
    }

    // below function for ajax calling
    public function listOrgs(Request $request, $area = '')
    {
		$openldap = new LdapServiceProvider();
		$data = $openldap->getOrgs();
		$orgs = array();
		if (is_array($data)) {
			foreach ($data as $org) {
				if (empty($area) || isset($org->st) && $area == $org->st) $orgs[] = $org;
			}
		}
		return response()->json($orgs, 200, JSON_UNESCAPED_UNICODE);
    }
    
    public function listClasses(Request $request, $dc, $grade = '')
    {
		$openldap = new LdapServiceProvider();
		$data = $openldap->getOus($dc, "教學班級");
		$classes = array();
		foreach ($data as $class) {
			if (empty($grade) || $grade == substr($class->ou, 0, 1)) $classes[] = $class;
		}
		return response()->json($classes, 200, JSON_UNESCAPED_UNICODE);
    }

    public function listTeachers(Request $request, $dc, $ou)
    {
		$json = array();
		$openldap = new LdapServiceProvider();
		$teachers = $openldap->findUsers("(&(o=$dc)(ou=*$ou)(inetUserStatus=active))", ["cn","displayName","o","ou","title"]);
		foreach ($teachers as $one) {
			$teacher = new \stdClass;
			$teacher->idno = $one['cn'];
            $teacher->name = $one['displayName'];           
            if (isset($one['titleName'][$dc])) {
                foreach ($one['titleName'][$dc] as $role) {
                    $a = explode(',', $role->key);
                    if (count($a) == 3 && $a[0] == $dc && $a[1] == $ou) $teacher->title = $role->name;
                }
                if (!isset($teacher->title)) $teacher->title = $one['titleName'][$dc][0]->name;
            }

			$json[] = $teacher;
		}
		return response()->json($json, 200, JSON_UNESCAPED_UNICODE);
    }
}
