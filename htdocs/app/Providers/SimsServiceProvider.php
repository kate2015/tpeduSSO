<?php

namespace App\Providers;

use Log;
use Illuminate\Support\ServiceProvider;

class SimsServiceProvider extends ServiceProvider
{
    private static $oauth_ps = null;
    private static $oauth_js = null;
    private static $oauth_hs = null;
    private static $seme = null;
    private static $error = '';

    public function __construct()
    {
        if (is_null(self::$oauth_ps))
            self::$oauth_ps = new \GuzzleHttp\Client([
                'verify' => false,
                'base_uri' => config('sims.ps.base_uri'),
            ]);
        if (is_null(self::$oauth_js))
            self::$oauth_js = new \GuzzleHttp\Client([
                'verify' => false,
                'base_uri' => config('sims.js.base_uri'),
            ]);
        if (is_null(self::$oauth_hs))
            self::$oauth_hs = new \GuzzleHttp\Client([
                'verify' => false,
                'base_uri' => config('sims.hs.base_uri'),
            ]);
        self::$seme = $this->seme();
    }

    public function error()
    {
        return self::$error;
    }

    public function hs_send($url)
    {
        //get token
        $response = self::$oauth_hs->request('POST', config('sims.hs.token'), [
            'form_params' => [
                'account' => config('sims.hs.oauth_account'),
                'password' => config('sims.hs.oauth_password'),
            ],
            'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'http_errors' => false,
        ]);
        $json = json_decode((string) $response->getBody());
        $token = $json->token;

        $data = self::$oauth_hs->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);
        return $data;
    }

    public function hs_call($info, array $replacement = null)
    {
        if (empty($info)) return false;
        if (!empty($replacement)) {
            $search = array();
            $values = array();
            foreach ($replacement as $key => $data) {
                $search[] = '{'.$key.'}';
                $values[] = $data;
            }
            $url = str_replace($search, $values, config("sims.hs.$info"));
        } else {
            $url = config("sims.hs.$info");
        }
        $res = $this->hs_send($url);
        $json = json_decode((string) $res->getBody());
        if (isset($json->Message)) {
            self::$error = $json->Message;
            if (config('sims.hs.debug')) Log::debug('Oauth call:'.$url.' failed! Server response:'.$res->getBody());
            return false;
        } else {
            if (isset($json->Data)) return $json->Data;
            return false;
        }
    }

    public function js_send($url)
    {
        //SHA1
        $t = time();
        $e = strtoupper(sha1( config('sims.js.oauth_id') . 'time' . $t . config('sims.js.oauth_secret')));

        $response = self::$oauth_js->request('POST', $url, [
            'json' => [
                'appKey' => config('sims.js.oauth_id'),
                'sign' => $e,
                'time' => $t,
            ],
            'headers' => [ 'Accept' => 'application/json' ],
            'http_errors' => false,
        ]);
        return $response;
    }

    public function js_call($info, array $replacement = null)
    {
        if (empty($info)) return false;
        if (!empty($replacement)) {
            $search = array();
            $values = array();
            foreach ($replacement as $key => $data) {
                $search[] = '{'.$key.'}';
                $values[] = $data;
            }
            $url = str_replace($search, $values, config("sims.js.$info"));
        } else {
            $url = config("sims.js.$info");
        }
        $res = $this->js_send($url);
        $json = json_decode((string) $res->getBody());
        if (isset($json->statusCode) && $json->statusCode != '00') {
            self::$error = $json->statusMsg;
            if (config('sims.js.debug')) Log::debug('Oauth call:'.$url.' failed! Server response:'.$res->getBody());
            return false;
        } else {
            if (isset($json->DATA_LIST)) return $json->DATA_LIST;
            if (isset($json->DATA_MAP)) return $json->DATA_MAP;
            return false;
        }
    }

    public function ps_send($url)
    {
        //AES-128-CBC
/*        $p = md5(config('sims.ps.oauth_secret'), true);
        $m = 'aes-128-cbc';
        $iv = md5(config('sims.ps.aes_iv') . date('YmdH'), true);
        $e = base64_encode(openssl_encrypt($url, $m, $p, OPENSSL_ZERO_PADDING, $iv));

        $response = self::$oauth_ps->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Special key '.config('sims.ps.oauth_id'),
                'SpecialVerify' => $e,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);
*/
        $response = self::$oauth_ps->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Special ip '.config('sims.ps.oauth_id'),
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);
        return $response;
    }

    public function ps_call($info, array $replacement)
    {
        if (!is_array($replacement)) return;
        $search = array();
        $values = array();
        foreach ($replacement as $key => $data) {
            $search[] = '{'.$key.'}';
            $values[] = $data;
        }
        $search[] = "{seme}";
        $values[] = self::$seme;
        $url = str_replace($search, $values, config("sims.ps.$info"));
        $res = $this->ps_send($url);
        $json = json_decode((string) $res->getBody());
        if (isset($json->status) && $json->status == 'ok') {
            if (isset($json->list)) return $json->list;
            return false;
        } else {
            if (isset($json->error)) self::$error = $json->error;
            if (config('sims.ps.debug')) Log::debug('Oauth call:'.$url.' failed! Server response:'.$res->getBody());
            return false;
        }
    }

    public function hs_getUnits($sid)
    {
        if (empty($sid)) return false;
        return $this->hs_call('units_info', ["sid" => $sid]);
    }

    public function js_getUnits($sid)
    {
        if (empty($sid)) return false;
        return $this->js_call('units_info', ["sid" => $sid]);
    }

    public function hs_getRoles($sid, $unit = '')
    {
        if (empty($sid)) return false;
        $roles = array();
        if (empty($unit)) {
            $units = $this->hs_getUnits($sid);
        } else {
            $units[$unit] = $unit;
        }
        foreach ($units as $ou => $name) {
            $data = $this->hs_call('roles_info', [ "sid" => $sid, "ou" => $ou ]);
            usleep(100);
            if ($data) $roles = array_merge($roles, $data);
        }
		if (!empty($roles)) return $roles;
		return false;
    }

    public function js_getRoles($sid, $unit = '')
    {
        if (empty($sid)) return false;
        $roles = array();
        if (empty($unit)) {
            $units = $this->js_getUnits($sid);
        } else {
            $units[$unit] = $unit;
        }
        foreach ($units as $ou => $name) {
            $data = $this->js_call('roles_info', [ "sid" => $sid, "ou" => $ou ]);
            usleep(100);
            if ($data) $roles = array_merge($roles, $data);
        }
		if (!empty($roles)) return $roles;
		return false;
    }

    public function hs_getClasses($sid)
    {
        if (empty($sid)) return false;
        $classes = array();
        $data = $this->hs_call('classes_info', ["sid" => $sid]);
		if ($data) {
			foreach ($data as $cls) {
				$classes[$cls->ou] = $cls->name;
			}
            return $classes;
		} else {
            return false;
        }
    }

    public function js_getClasses($sid)
    {
        if (empty($sid)) return false;
        $classes = array();
        $data = $this->js_call('classes_info', ["sid" => $sid]);
		if ($data) {
			foreach ($data as $cls) {
				$classes[$cls->ou] = $cls->name;
			}
            return $classes;
		} else {
            return false;
        }
    }

    public function ps_getClasses($sid)
    {
        if (empty($sid)) return false;
        $classes = $this->ps_call('classes_info', ["sid" => $sid]);
        return $classes;
    }

    public function hs_getSubjects($sid)
    {
        $subjects = array();
		$data = $this->hs_call('subjects_info', ['sid' => $sid]);
		if ($data) {
			foreach ($data as $subj) {
				$subjects['subj'.$subj->subject] = $subj->name;
			}
            return $subjects;
		} else {
            return false;
        }
    }

    public function js_getSubjects($sid)
    {
        $subjects = array();
		$data = $this->js_call('subjects_info', ['sid' => $sid]);
		if ($data) {
			foreach ($data as $subj) {
				$subjects['subj'.$subj->subject] = $subj->name;
			}
            return $subjects;
		} else {
            return false;
        }
    }

    public function ps_getSubjects($sid)
    {
        $subjects = array();
        $classes = $this->ps_getClasses($sid);
        if (!empty($classes)) {
		    foreach ($classes as $class) {
			    $data = $this->ps_call('subject_for_class', [ 'sid' => $sid, 'clsid' => $class->clsid ]);
			    if (!empty($data[0]->subjects)) {
				    $class_subjects = $data[0]->subjects;
				    foreach ($class_subjects as $subj) {
					    $subj_name = array_keys((array)$subj)[0];
					    if (!in_array($subj_name, $subjects)) $subjects[] = $subj_name;
				    }
			    } else {
                    return false;
                }
            }
        }
        return $subjects;
    }

    public function hs_getTeachers($sid, $class = '')
    {
        if (empty($sid)) return false;
        $teachers = $this->hs_call('teachers_info', ["sid" => $sid]);
        if (!empty($teachers)) return $teachers;
        else return false;
    }

    public function js_getTeachers($sid, $class = '')
    {
        if (empty($sid)) return false;
        $teachers = $this->js_call('teachers_info', ["sid" => $sid]);
        if (!empty($teachers)) return $teachers;
        else return false;
    }

    public function ps_getTeachers($sid, $class = '')
    {
        if (empty($sid)) return false;
        $teachers = array();
        if (empty($class)) {
            $data = $this->ps_call('teachers_info', ["sid" => $sid]);
            if (!empty($data)) {
                foreach ($data as $teacher) {
                    $teachers[] = $teacher->teaid;
                }
            }
        } else {
            $classes = array();
            if (is_array($class)) {
                $classes = $class;
            } else {
                $classes[] = $class;
            }
            foreach ($classes as $clsid) {
                $data = $this->ps_call('teachers_in_class', ["sid" => $sid, "clsid" => $clsid]);
                usleep(100);
                if ($data) $teachers = array_merge($teachers, $data);
            }
            $teachers = array_values(array_unique($teachers));
        }
        if (!empty($teachers)) return $teachers;
        else return false;
    }

    public function hs_getStudents($sid, $class = '')
    {
        if (empty($sid)) return false;
        $students = array();
        if (empty($class)) {
            $classes = $this->hs_getClasses($sid);
        } else {
            $classes[$class] = $class;
        }
        foreach ($classes as $clsid => $cls_name) {
            $data = $this->hs_call('students_in_class', ["sid" => $sid, "clsid" => $clsid]);
            usleep(100);
            if ($data) $students = array_merge($students, $data);
        }
        $students = array_values(array_unique($students));
        if (!empty($students)) return $students;
        else return false;
    }

    public function js_getStudents($sid, $class = '')
    {
        if (empty($sid)) return false;
        $students = array();
        if (empty($class)) {
            $classes = $this->js_getClasses($sid);
        } else {
            $classes[$class] = $class;
        }
        foreach ($classes as $clsid => $cls_name) {
            $data = $this->js_call('students_in_class', ["sid" => $sid, "clsid" => $clsid]);
            usleep(100);
            if ($data) $students = array_merge($students, $data);
        }
        $students = array_values(array_unique($students));
        if (!empty($students)) return $students;
        else return false;
    }

    public function ps_getStudents($sid, $class = '')
    {
        if (empty($sid)) return false;
        $students = array();
        if (empty($class)) {
            $classes = $this->ps_getClasses($sid);
            $classes = array_map(function($c) { return $c->clsid; }, $classes);
        } else {
            $classes[] = $class;
        }
        foreach ($classes as $c) {
            $stu = $this->ps_call('students_in_class', ["sid" => $sid, "clsid" => $c]);
            usleep(100);
            if ($stu) $students = array_merge($students, $stu[0]->students);
        }
        return $students;
    }

    public function hs_getPerson($sid, $idno)
    {
        if (empty($sid) || empty($idno)) return false;
        $data = $this->hs_call('person_info', [ 'sid' => $sid, 'idno' => $idno ]);
        if ($data) return (array)$data;
        else return false;
    }

    public function js_getPerson($sid, $idno)
    {
        if (empty($sid) || empty($idno)) return false;
        $data = $this->js_call('person_info', [ 'sid' => $sid, 'idno' => $idno ]);
        if ($data) return (array)$data;
        else return false;
    }

    public function ps_getTeacher($sid, $teaid)
    {
        if (empty($sid) || empty($teaid)) return false;
        $data1 = $this->ps_call('teacher_info', [ 'sid' => $sid, 'teaid' => $teaid ]);
        $data2 = $this->ps_call('teacher_detail', [ 'sid' => $sid, 'teaid' => $teaid ]);
        $data3 = $this->ps_call('teacher_schedule', [ 'sid' => $sid, 'teaid' => $teaid ]);
        if (isset($data1[0]))
            $data1 = (array)$data1[0];
        else 
            $data1 = array();
        if (isset($data2[0]))
            $data2 = (array)$data2[0];
        else
            $data2 = array();
        if (isset($data3[0])) {
            $assign = array();
            $classes = $data3[0]->classes;
            foreach ($classes as $c) {
                $class = $c->id;
                $subjects = $c->subjects;
                foreach ($subjects as $s) {
                    $s = (array)$s;
                    $assign[$class][] = key($s);                    
                }
            }
            return array_merge($data1, $data2, [ 'assign' => $assign ]);
        } else {
            return array_merge($data1, $data2);
        }
    }

    public function ps_getStudent($sid, $stdno)
    {
        if (empty($sid) || empty($stdno)) return false;
        $data1 = $this->ps_call('student_info', [ 'sid' => $sid, 'stdno' => $stdno ]);
        $data2 = $this->ps_call('student_detail', [ 'sid' => $sid, 'stdno' => $stdno ]);
        if (isset($data1[0]))
            $data1 = (array)$data1[0];
        else 
            $data1 = array();
        if (isset($data2[0]))
            $data2 = (array)$data2[0];
        else
            $data2 = array();
        return array_merge($data1, $data2);
    }

    private function seme() {
        if (date('m') > 7) {
          $year = date('Y') - 1911;
          $seme = 1;
        }
        elseif (date('m') < 2) {
          $year = date('Y') - 1912;
          $seme = 1;
        }
        else {
          $year = date('Y') - 1912;
          $seme = 2;
        }
        return $year.$seme;
    }
};
