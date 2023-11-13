@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                <h5><i class='fas fa-edit'></i> <b>{{ $role->name }}</b> Role</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ url('users/roles') }}">Roles</a></li>
                        <li class="breadcrumb-item active">View</li>
                    </ol>
                </div><!-- /.col -->
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <!-- Small boxes (Stat box) -->
        <div class="row">
            <div class="col-md-12 mb-3">

                <!-- small box -->
                <div class="card">
                    <div class="card-header">
                        <h5><i class='fas fa-user-lock'></i> Permissions</h5>
                    </div>
                    <div class='card-body'>
                        <div class="form-group form-check">
                            <input type="checkbox" name='permissions[]' class="form-check-input" id="checkAll">
                            <label class="form-check-label" for="checkAll">Check All</label>
                        </div>
                        <form method='POST' action="{{ url('users/roles/permissions/add') }}" class='row' id='permissions-form'>
                            @csrf
                            <input type='hidden' name='id' value='{{ $role->id }}'>
                            @foreach ($permissions as $permission)
                                <div class='col-6 col-sm-4 col-md-3 col-lg-2'>
                                    <div class="form-group form-check">
                                        <input type="checkbox" name='permissions[]' value="{{ $permission->id }}" class="form-check-input" id="permission{{ $permission->id }}" {{ $permission->roles->count() > 0?'checked':'' }}>
                                        <label class="form-check-label" for="permission{{ $permission->id }}">{{ $permission->name }}</label>
                                    </div>
                                </div>
                            @endforeach
                            <div class='col-sm-12 text-right'>
                                <div class='alert feedback border d-none text-left'>
                                    <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                                </div>
                                @can('Edit Roles')
                                    <button class='btn btn-primary'>Save Permissions</button>
                                @endcan
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <!-- ./col -->
        </div>
        <!-- /.row -->
    </div><!-- /.container-fluid -->
</section>
<!-- /.content -->

@endsection
@push('js')
<script>
    $(document).ready(function () {
        $('#checkAll').change(function(){
            $("input:checkbox").prop('checked', $(this).prop("checked"));
        });
        $('#permissions-form').submit(function (e) {
            e.preventDefault();
            var btn = $(this).find('.btn');
            btn.attr('disabled', 'disabled');
            $('.feedback').removeClass('d-none');
            $('.feedback').removeClass('alert-danger');
            $('.feedback').removeClass('alert-success');
            $('.feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
            var formData = $(this).serialize();
            $.ajax({
                url: '{{ url("users/roles/permissions/add") }}',
                type: 'POST',
                data: formData
            }).done(function (data) {
                $('.feedback').addClass('alert-success');
                $('.feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                setTimeout(() => {
                    $('.feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            }).fail(function (response) {
                let data = response.responseJSON;
                $('.feedback').addClass('alert-danger');
                $('.feedback').html("");
                if (data.errors) {
                    if (data.errors.id) {
                        $('.feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.id + "<br>");
                    }
                    if (data.errors.permissions) {
                        $('.feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.permissions + "<br>");
                    }
                } else if (data.error) {
                    $('.feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                } else {
                    $('.feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                }
                setTimeout(() => {
                    $('.feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            });
        });
        $(document).on('click', '.table .btn-edit', function(){
            $('.modal-title span').text("Edit ");
            var row = $(this).closest('tr');
            var id = row.find('.id').text();
            var name = row.find('.name').text();

            $('input[name=id]').val(id);
            $('input[name=name]').val(name);
        });
    });
</script>
@endpush