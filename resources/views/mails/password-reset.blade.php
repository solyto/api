<style>
    body {
        font-family: sans-serif;
    }
</style>

<div style="width: 100%; height: 4px; background: linear-gradient(90deg,rgba(20, 165, 205, 1) 0%, rgba(97, 217, 106, 1) 100%); content: ' ';"></div>
<div style="padding: 5px;">
    <h3 style="margin-top: 20px;">{{ __('mail.greeting', ['name' => $name]) }}</h3>
    <p>{{ __('mail.reset_intro') }}</p>
    <br>
    @php $resetUrl = config('app.frontend_url') . '/auth/reset-password?token=' . $token . '&email=' . $email . ($platform !== 'web' ? '&platform=' . $platform : ''); @endphp
    <a href="{{ $resetUrl }}" style="padding: 10px; background-color: #14a5cd; color: #ffffff; font-weight: bold; font-size: 16px; text-decoration: none;">{{ __('mail.reset_button') }}</a>
    <br><br><br>
    {{ __('mail.reset_ignore') }}
    <br><br>
    {{ __('mail.reset_trouble') }} <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
</div>
