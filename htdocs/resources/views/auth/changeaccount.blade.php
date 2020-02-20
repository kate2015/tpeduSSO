@extends('layouts.app')
@section('content')
<div class="container">
    <div class="row justify-content-center">
	<div class="col-md-8 col-md-offset-2">
	    <div class="card card-default" style="margin-top: 20px">
		<div class="card-header">變更（建立）帳號</div>
		<div class="card-body">
		@if (session('error'))
		    <div class="alert alert-danger">
			{{ session('error') }}
		    </div>
		@endif
		@if (session('success'))
		    <div class="alert alert-success">
			{{ session('success') }}
		    </div>
		@endif
		<p>注意：您使用的預設帳號在您畢業（或離職）後會自動刪除，請務必修改為自訂帳號才能長久保留使用！</p>
		<form class="form-horizontal" method="POST" action="{{ route('changeAccount') }}">
		    {{ csrf_field() }}
		    <div class="form-group{{ $errors->has('new-account') ? ' has-error' : '' }}">
			<label for="new-account" class="col-md-4 col-form-label text-md-right">新帳號</label>
			<div class="col-md-8">
			    <input id="new-account" type="text" class="form-control" name="new-account" required>
			    @if ($errors->has('new-account'))
			    <span class="help-block">
				<strong>{{ $errors->first('new-account') }}</strong>
			    </span>
			    @endif
			</div>
		    </div>
		    <div class="form-group">
			<div class="col-md-8 col-md-offset-4">
			    <button type="submit" class="btn btn-primary">
				確定
			    </button>
			</div>
		    </div>
		</form>
	        </div>
	    </div>
	</div>
    </div>
</div>
@endsection
