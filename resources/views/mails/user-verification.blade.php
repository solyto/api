<style>
    body {
        font-family: sans-serif;
    }
</style>

<div style="width: 100%; height: 4px; background: linear-gradient(90deg,rgba(20, 165, 205, 1) 0%, rgba(97, 217, 106, 1) 100%); content: ' ';"></div>
<div style="padding: 5px;">
    <h3 style="margin-top: 20px;">{{ __('mail.greeting', ['name' => $name]) }}</h3>
    <p>{{ __('mail.verification_intro') }}</p>
    <br>
    @php $verifyUrl = config('app.frontend_url') . '/auth/verify/' . $user_id . '/' . $token . ($platform !== 'web' ? '?platform=' . $platform : ''); @endphp
    <a href="{{ $verifyUrl }}" style="padding: 10px; background-color: #14a5cd; color: #ffffff; font-weight: bold; font-size: 16px; text-decoration: none;">{{ __('mail.verification_button') }}</a>
    <br><br><br>
    {{ __('mail.verification_ignore') }}
    <br><br>
    {{ __('mail.verification_trouble') }} <a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a>
</div>
