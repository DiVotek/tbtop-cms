@section('subject', __('tbtop-cms::notifications.mail.subject'))
@foreach ($fields as $key => $value)
<p><strong>{{ $key }}:</strong> {!! nl2br(e($value)) !!}</p>
@endforeach
<p><strong>{{ __('tbtop-cms::notifications.mail.fields.page') }}:</strong> {{ $pageName }}</p>
<p><strong>{{ __('tbtop-cms::notifications.mail.fields.locale') }}:</strong> {{ $locale }}</p>
<p><strong>{{ __('tbtop-cms::notifications.mail.fields.submitted_at') }}:</strong> {{ $submittedAt }}</p>
<p><a href="{{ $actionUrl }}">{{ __('tbtop-cms::notifications.mail.view_submission') }}</a></p>
