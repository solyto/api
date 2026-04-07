<style>
    body {
        font-family: sans-serif;
    }
</style>

<div style="width: 100%; height: 4px; background: linear-gradient(90deg,rgba(20, 165, 205, 1) 0%, rgba(97, 217, 106, 1) 100%); content: ' ';"></div>
<div style="padding: 5px;">
    <h3 style="margin-top: 20px;">Hello, {{ $name }}!</h3>
    <p>Please click the button below to verify your email address.</p>
    <br>
    <a href="{{ config('app.frontend_url') }}/auth/verify/{{ $user_id }}/{{ $token }}" style="padding: 10px; background-color: #14a5cd; color: #ffffff; font-weight: bold; font-size: 16px; text-decoration: none;">Verify Email Address</a>
    <br><br><br>
    If you did not create an account, please ignore this mail.
    <br><br>
    If you're having trouble clicking the button, please copy and paste this URL into your web browser: <a href="{{ config('app.frontend_url') }}/auth/verify/{{ $user_id }}/{{ $token }}">{{ config('app.frontend_url') }}/auth/verify/{{ $user_id }}/{{ $token }}</a>
</div>
