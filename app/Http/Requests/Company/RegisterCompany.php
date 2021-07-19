<?php

namespace App\Http\Requests\Company;

use App\GoogleCaptchaSetting;
use App\Http\Requests\CoreRequest;
use App\Rules\Captcha;

class RegisterCompany extends CoreRequest
{

    public function rules()
    {
        $google_captcha = GoogleCaptchaSetting::first();

        $rules =  [
            'business_name' => 'required',
            'email' => 'required|email|unique:companies,company_email|unique:users,email',
            'contact' => 'required|min:10|max:13',
            'address' => 'required',
            'name' => 'required|string|required_without:numeric',
            'password' => 'required|min:6'
            ];

            if($google_captcha->status == 'active')
            {
                $rules['recaptcha'] = 'required';
            }

            return $rules;
    }

}
