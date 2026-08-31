@section('subject', __('tbtop-cms::notifications.bell.title'))
<b>{{ __('tbtop-cms::notifications.telegram.message_title') }}</b>
{{ $leadIdentity }}
{{ __('tbtop-cms::notifications.mail.fields.page') }}: {{ $pageName }}
{{ $actionUrl }}
