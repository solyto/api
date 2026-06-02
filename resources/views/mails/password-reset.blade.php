<style>
    body {
        font-family: sans-serif;
    }
</style>

<div style="width: 100%; height: 4px; background: linear-gradient(90deg,rgba(20, 165, 205, 1) 0%, rgba(97, 217, 106, 1) 100%); content: ' ';"></div>
<div style="padding: 5px;">
    <h3 style="margin-top: 20px;">Hello, {{ $name }}!</h3>
    <p>Click the button below to reset your Solyto password. This link expires in 60 minutes and can only be used once.</p>
    <br>
    <a href="{{ config('app.frontend_url') }}/auth/reset-password?token={{ $token }}&email={{ $email }}" style="padding: 10px; background-color: #14a5cd; color: #ffffff; font-weight: bold; font-size: 16px; text-decoration: none;">Reset Password</a>
    <br><br><br>
    If you did not request a password reset, you can safely ignore this email.
    <br><br>
    If you're having trouble clicking the button, copy and paste this URL into your browser: <a href="{{ config('app.frontend_url') }}/auth/reset-password?token={{ $token }}&email={{ $email }}">{{ config('app.frontend_url') }}/auth/reset-password?token={{ $token }}&email={{ $email }}</a>
</div>
