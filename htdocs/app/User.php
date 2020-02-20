<?php

namespace App;

use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Providers\LdapServiceProvider;;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
		'uuid', 'idno', 'name', 'email', 'mobile', 'password', 'is_admin', 'is_parent',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'is_admin', 'is_parent',
    ];
    
    protected $appends = [
		'ldap',
    ];
    
    protected $casts = [
		'is_admin' => 'boolean',
		'is_parent' => 'boolean',
		'email_verified_at' => 'datetime',
    ];

	public function gmails()
	{
    	return $this->hasMany('App\Gsuite', 'idno', 'idno');
	}

	public function socialite_accounts()
	{
    	return $this->hasMany('App\SocialiteAccount', 'idno', 'idno');
	}

	public function nameID()
	{
		$gsuite = $this->gmails()->where('primary', 1)->first();
		if ($gsuite) return $gsuite->nameID;
    	return false;
	}

    public function getLdapAttribute()
    {
		if ($this->attributes['is_parent']) return false;
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($this->attributes['idno']);
		$data = $openldap->getUserData($entry);
		if (array_key_exists('entryUUID', $data)) {
			$this->attributes['uuid'] = $data['entryUUID'];
		}
		if (array_key_exists('mail', $data)) {
	    	if (is_array($data['mail'])) {
				$this->attributes['email'] = $data['mail'][0];
		    } else {
		    	$this->attributes['email'] = $data['mail'];
		    }
		}
		if (array_key_exists('mobile', $data)) {
	    	if (is_array($data['mobile'])) {
				$this->attributes['mobile'] = $data['mobile'][0];
		    } else {
		    	$this->attributes['mobile'] = $data['mobile'];
		    }
		}
		if (array_key_exists('displayName', $data)) {
		    $this->attributes['name'] = $data['displayName'];
		}
		if (array_key_exists('birthDate', $data)) {
		    $data['birthDate'] = substr($data['birthDate'],0,8);
		}
		return $data;
    }
    
    public function sendPasswordResetNotification($token)
    {
		if ($this->attributes['is_parent']) {
			$accounts = $this->attributes['email'];
		} else {
			$openldap = new LdapServiceProvider();
			$entry = $openldap->getUserEntry($this->attributes['idno']);
			$data = $openldap->getUserData($entry, 'uid');
			$accounts = '';
			if (array_key_exists('uid', $data)) {
				if (is_array($data['uid'])) {
					$accounts = implode('、', $data['uid']);
				} else {
					$accounts = $data['uid'];
				}
			} else {
				$accounts = '尚未設定帳號，請使用 cn='.$this->attributes['idno'].' 登入設定！';
			}
		}
		$this->notify(new ResetPasswordNotification($token, $accounts));
    }
    
    public function resetLdapPassword($value)
    {
		if ($this->attributes['is_parent']) return;
		$openldap = new LdapServiceProvider();
		$ssha = $openldap->make_ssha_password($value);
		$new_passwd = array( 'userPassword' => $ssha );
		$accounts = array();
		if (isset($this->ldap['uid'])) {
			if (is_array($this->ldap['uid'])) {
				$accounts = $this->ldap['uid'];
			} else {
				$accounts[] = $this->ldap['uid'];
			}
			foreach ($accounts as $account) {
				$entry = $openldap->getAccountEntry($account);
				if ($entry) $openldap->updateData($entry,$new_passwd);
			}
		}
		$entry = $openldap->getUserEntry($this->attributes['idno']);
		if ($entry) $openldap->updateData($entry,$new_passwd);
    }

    public function findForPassport($username)
    {
		$user = $this->where('email', $username)->first();
		if ($user) {
			return $user;
		} else {
			$openldap = new LdapServiceProvider();
			$id = $openldap->checkAccount($username);
			if ($id) {
				$user = $this->where('idno', $id)->first();
				return $user;
			}	
		}
    }

	public function account()
    {
		$accounts = array();
		if (isset($this->ldap['uid'])) {
			if (is_array($this->ldap['uid'])) {
				$accounts = $this->ldap['uid'];
			} else {
				$accounts[] = $this->ldap['uid'];
			}
			for ($i=0;$i<count($accounts);$i++) {
				if (is_numeric($accounts[$i])) {
					unset($accounts[$i]);
					continue;
				}
				if (strpos($accounts[$i], '@')) {
					unset($accounts[$i]);
					continue;
				}
			}
			if (!empty($accounts)) {
				$account = strtolower((array_values($accounts))[0]);
				return $account;
			}
		}
		return false;
    }

    public function is_default_account()
    {
		$openldap = new LdapServiceProvider();
		$account = $this->account();
		if ($account && preg_match("/^([a-z]+)[0-9]+/", $account, $matches) && $openldap->checkSchool($matches[1])) return true;
		return false;
    }

}
