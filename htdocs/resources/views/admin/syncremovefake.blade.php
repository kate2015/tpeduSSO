@extends('layouts.syncboard')

@section('page_heading')
移除假身分人員
@endsection

@section('section')
<div class="container">
	<div class="row">
	@if ($result)
	    <div class="alert alert-info">
	    @foreach ($result as $line)
		{{ $line }}<br>
		@endforeach
	    </div>
	@endif
	</div>
</div>
@endsection
