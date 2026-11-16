@props(['type' => 'info'])
<div {{ $attributes->class(['a-notice', 'a-notice--'.$type]) }} @if($type === 'error') role="alert" @elseif($type === 'success') role="status" @endif>{{ $slot }}</div>
