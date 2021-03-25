<?php
	
	namespace App\Http\Requests;
	
	use Illuminate\Foundation\Http\FormRequest;
	
	class LoginRequest extends FormRequest
	{
		/**
		 * Determine if the user is authorized to make this request.
		 *
		 * @return bool
		 */
		public function authorize()
		{
			return true;
		}
		
		/**
		 * Get the validation rules that apply to the request.
		 *
		 * @return array
		 */
		public function rules()
		{
			return [
					'email' => 'required|string',
					'password' => 'required|string|min:4',
			];
		}
		
		public function messages()
		{
			return [
					'email.required' => 'Your phone number or email address is required',
					'password.required' => 'Your account password is required',
			];
		}
	}
