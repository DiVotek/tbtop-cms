@section('subject', __('tbtop-cms::notifications.bell.title'))
<b>{{ __('tbtop-cms::notifications.telegram.message_title') }}</b>
@if ($leadName === '' && $leadContact === '')
{{ $leadIdentity }}
@endif
@if ($leadName !== '')
{{ $leadName }}
@endif
@if ($leadContact !== '')
{{ $leadContact }}
@endif
{{ __('tbtop-cms::notifications.mail.fields.page') }}: {{ $pageName }}
{{ $actionUrl }}
