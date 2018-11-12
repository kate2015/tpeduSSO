@extends('layouts.dashboard', [ 'category' => $category, 'dc' => $dc ])

@section('page_heading')
同步年班座號
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
	@else
		<p>將從校務行政系統將學生就讀班級座號同步到 openldap，同步過程中會自動將畢業學生標註為刪除，但無法新增學生和處理學籍異動（轉入或轉出）。</p>
		<p>同步過程需要時間，直到結果出現為止，請勿關閉瀏覽器或離開此網頁，以避免同步程序被關閉。</p>			
		<form role="form" method="POST" action="{{ route('school.ps.sync_seat', [ 'dc' => $dc ]) }}">
			@csrf
			<div class="form-group">
				<button class="btn btn-default" type="submit" name="submit" value="true">
					我瞭解了，請開始同步
				</button>
			</div>
		</form>
	@endif
	</div>
</div>
@endsection