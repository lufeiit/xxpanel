<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserCheckin extends FormRequest
{
    /**
     * 获取适用于请求的验证规则.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'input' => 'required|string'
        ];
    }

    /**
     * 获取验证器错误的自定义消息.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'input.required' => '请输入数值和单位',
            'input.string' => '参数必须是字符串'
        ];
    }

    /**
     * 确定用户是否有权发出此请求.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }
}