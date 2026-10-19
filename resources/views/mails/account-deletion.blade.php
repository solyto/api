<style>
    body {
        font-family: sans-serif;
    }
</style>

<div style="width: 100%; height: 4px; background: linear-gradient(90deg,rgba(20, 165, 205, 1) 0%, rgba(97, 217, 106, 1) 100%); content: ' ';"></div>
<div style="padding: 5px;">
    <h3 style="margin-top: 20px;">{{ __('mail.greeting', ['name' => $name]) }}</h3>
    <p>{{ __('mail.delete_intro') }}</p>
    <br>
    @php $deleteUrl = config('app.frontend_url') . '/auth/confirm-account-deletion?token=' . $token . '&email=' . $email . ($platform !== 'web' ? '&platform=' . $platform : ''); @endphp
    <a href="{{ $deleteUrl }}" style="padding: 10px; background-color: #dc2626; color: #ffffff; font-weight: bold; font-size: 16px; text-decoration: none;">{{ __('mail.delete_button') }}</a>
    <br><br><br>
    {{ __('mail.delete_ignore') }}
    <br><br>
    {{ __('mail.delete_trouble') }} <a href="{{ $deleteUrl }}">{{ $deleteUrl }}</a>
</div>
