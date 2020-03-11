<?php

namespace App\Providers;

use Illuminate\Support\Str;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use App\Providers\LdapServiceProvider;
use App\User;

class LdapUserProvider extends EloquentUserProvider
{

    public function retrieveByCredentials(array $credentials)
    {
		if (empty($credentials) ||
           (count($credentials) === 1 &&
            array_key_exists('password', $credentials))) {
            return;
        }
		$openldap = new LdapServiceProvider();
		$id = false;
		if (isset($credentials['username'])) {
			if (substr($credentials['username'],0,3) == 'cn=') {
				$id = $openldap->checkIdno($credentials['username']);
			} else {
				$id = $openldap->checkAccount($credentials['username']);
			}
		}
		if (isset($credentials['email'])) {
			$id = $openldap->checkEmail($credentials['email']);
		}
		if (isset($credentials['mobile'])) {
			$id = $openldap->checkMobile($credentials['mobile']);
		}
		if ($id) {
			$entry = $openldap->getUserEntry($id);
			$data = $openldap->getUserData($entry);
			$user = User::where('idno', $id)->orWhere('uuid', $data['entryUUID'])->firstOrNew([
				'idno' => $id,
				'uuid' => $data['entryUUID'],
			]);
			if (isset($credentials['password'])) {
				$user->password = \Hash::make($credentials['password']);
			} else {
				$user->password = \Hash::make(substr($id,-6));
			}
			$user->name = $data['displayName'];
			if (isset($credentials['email'])) {
				$user->email = $credentials['email'];
			} elseif (!empty($data['mail'])) {
				if (is_array($data['mail']))
					$user->email = $data['mail'][0];
				else
					$user->email = $data['mail'];
				if (!$openldap->emailAvailable($id, $user->email)) $user->email = null;
			} else $user->email = null;
			if (!empty($data['mobile'])) {
				if (is_array($data['mobile']))
					$user->mobile = $data['mobile'][0];
				else
					$user->mobile = $data['mobile'];
				if (!$openldap->mobileAvailable($id, $user->mobile)) $user->mobile = null;
			} else $user->mobile = null;
			$user->save();
			return $user;
		} else {
			if (isset($credentials['username'])) return User::where('email', $credentials['username'])->first();
			if (isset($credentials['email'])) return User::where('email', $credentials['email'])->first();
		}
	}

	public function validateCredentials(UserContract $user, array $credentials)
	{
		$openldap = new LdapServiceProvider();
		if (substr($credentials['username'],0,3) == 'cn=') {
			return $openldap->userLogin($credentials['username'], $credentials['password']);
		} else {
			if ($user->is_parent) 
				return $this->hasher->check($credentials['password'], $user->getAuthPassword());
			else
				return $openldap->authenticate($credentials['username'], $credentials['password']);
		}
	}
}
