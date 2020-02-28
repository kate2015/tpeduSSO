@extends('layouts.superboard')

@section('page_heading')
OAuth 用戶端管理
@endsection

@section('section')
<div class="container">
	<div class="row">
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
	<div class="col-sm-8">
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>OAuth 用戶端一覽表</h4>
		</div>
		<div class="panel-body">
			<table class="table table-hover">
				<thead>
					<tr>
						<th>申請單位</th>
						<th>應用平台名稱</th>
						<th>應用平台網址</th>
						<th>用戶端代號</th>
						<th>用戶端密鑰</th>
						<th>授權碼回傳網址</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					@foreach ($projects as $project)
					<tr>
		    			@csrf
						<td style="vertical-align: inherit;">
							<span>{{ $project->organization }}</span>
						</td>
						<td style="vertical-align: inherit;">
							<span>{{ $project->applicationName }}</span>
						</td>
						<td style="vertical-align: inherit;">
							<span>{{ $project->website }}</span>
						</td>
						<td style="vertical-align: inherit;">
							<span>{{ $project->client()->id }}</span>
						</td>
						<td style="vertical-align: inherit;">
							<span>{{ $project->client()->secret }}</span>
						</td>
						<td style="vertical-align: inherit;">
							<span>{{ $project->client()->redirect }}</span>
						</td>
						<td style="vertical-align: inherit;">
							<button type="button" class="btn btn-primary"
								onclick="$('#form').attr('action','{{ route('bureau.updateClient', [ 'id' => $project->id ]) }}');
										 $('#form').attr('method','GET');
										 $('#form').submit();">編輯</button>
							<button type="button" class="btn btn-info"
								onclick="$('#form').attr('action','{{ route('bureau.toggleClient', [ 'id' => $project->id ]) }}');
										 $('#form').submit();">{{ $project->client()->revoked ? '啟用' : '停用' }}</button>
				   		</td>
					</tr>
					@endforeach
				</tbody>
			</table>
		</div>
		</div>
	</div>
    <form id="form" action="" method="POST" style="display: none;">
    @csrf
    </form>
	</div>
</div>
@endsection