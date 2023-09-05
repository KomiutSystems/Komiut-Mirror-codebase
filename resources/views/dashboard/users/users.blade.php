@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                <h1 class="m-0"><i class='fas fa-users'></i> Users</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can("Add Users")
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#userModal"><i
                        class='fas fa-user-plus'></i> Add User</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Users</li>
                        </ol>
                    @endcan
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
                <div class="card card-primary card-outline">
                    <div class="card-body box-profile">
                        <form class='row' id='search-form'>
                            <div class="col-sm-4">
                                <label>Search</label>
                                <input type="text" class="form-control mb-1" name="search"
                                        placeholder="Search">
                            </div>
                            <div class="col-sm-4">
                                <label>Sacco</label>
                                <select class="form-control mb-1" name="sacco" id='search-sacco'>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label>Role</label>
                                <select class="form-control mb-1" name="role" id='search-roles'>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <label>Gender</label>
                                <select class="form-control mb-1" name="gender" id='search-gender'>
                                </select>
                            </div>
                            
                            <div class="col-sm-3">
                                <label>From Date</label>
                                <input type="text" class="form-control mb-1" name="from_date" id='from_date'
                                        placeholder="From Date">
                            </div>
                            <div class="col-sm-3">
                                <label>To Date</label>
                                <input type="text" class="form-control mb-1" name="to_date" id='to_date'
                                        placeholder="To Date">
                            </div>
                            <div class="col-sm-3">
                                <label>Status</label>
                                <select name="status" class="form-control mb-1">
                                    <option value='1'>Active</option>
                                    <option value='0'>In-Active</option>
                                </select>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class='table'>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>First Name</th>
                                        <th>Last Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Sacco</th>
                                        <th>Gender</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>DOB</th>
                                        <th class='text-end'>Action</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ./col -->
        </div>
        <!-- /.row -->
    </div><!-- /.container-fluid -->
</section>
<!-- /.content -->

<!-- Profile Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-user-plus'></i> <span>New User</span></h5>
                <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="{{ url('users/add') }}" class="row">
                    @csrf
                    <input type='hidden' name='id' value='0'>
                    <div class='col-sm-6 form-group'>
                        <label>First Name</label>
                        <input type='text' placeholder="First Name" name="firstname" class='form-control' autofocus
                            required />
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Last Name</label>
                        <input type='text' placeholder="Last Name" name="lastname" class='form-control' autofocus
                            required />
                    </div>
                    <div class='col-sm-12 form-group'>
                        <label>Email Address</label>
                        <input type='email' placeholder="Email Address" name="email" class='form-control' required />
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Date of Birth</label>
                        <input type='date' placeholder="Date of Birth" name="dob" class='form-control' required />
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Sacco</label>
                        <select id='sacco' name="sacco" class='form-control'></select>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Gender</label>
                        <select id='gender' name="gender" class='form-control'></select>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>User Role</label>
                        <select id='roles' name="role" class='form-control' id='roles'></select>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Phone Number</label>
                        <input type='text' placeholder="Phone Number" name="phone"
                            class='form-control' required />
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Status</label>
                        <select name="status" class='form-control'>
                            <option disabled>Status</option>
                            <option value='1'>Active</option>
                            <option value='0'>In-Active</option>
                        </select>
                    </div>
                    <div class='alert feedback border d-none'>
                        <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><i
                        class='fas fa-times'></i> Close</button>
                <button type="button" class="btn btn-primary btn-sm btnSave"><i class='fas fa-paper-plane'></i> Save
                    changes</button>
            </div>
        </div>
    </div>
</div>
@endsection
@push('js')
<script>
    $(document).ready(function () {
        
        flatpickr("#from_date, #to_date", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            //defaultDate: new Date(),
        });
        
        var sacco_id = "{{ $sacco != null?$sacco->id:0 }}";
        var sacco = "{{ $sacco != null?$sacco->name:0 }}";
        $('#search-sacco').select2({
            width: '100%',
            placeholder: 'Select Sacco',
            //dropdownParent: $('#saccoModal'),
            allowClear: sacco_id>0?false:true,
            ajax: {
                url: '{{url("saccos/search")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
        $('#sacco').select2({
            width: '100%',
            placeholder: 'Select Sacco',
            //dropdownParent: $('#userModal'),
            allowClear: sacco_id>0?false:true,
            ajax: {
                url: '{{url("saccos/search")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
        
        if(sacco_id > 0){
            var data = {
                id: sacco_id,
                text: sacco
            };
            var newOption1 = new Option(data.text, data.id, false, false);
            var newOption = new Option(data.text, data.id, false, false);
            $('#sacco').append(newOption1).trigger('change');
            $('#search-sacco').append(newOption).trigger('change');
        }

        var table = $('.table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ url('users/datatable/all') }}",
                data: function (d) {
                    d.search = $('#search-form input[name=search]').val();
                    d.from_date = $('#search-form input[name=from_date]').val();
                    d.to_date = $('#search-form input[name=to_date]').val();
                    d.sacco = $('#search-form select[name=sacco]').val();
                    d.role = $('#search-form select[name=role]').val();
                    d.gender = $('#search-form select[name=gender]').val();
                    d.to = $('#search-form select[name=to]').val();
                    d.status = $('#search-form select[name=status]').val();
                }
             },
            dom: 'lBtrip', //'lfBtrip'

            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable:false,searchable:false },
                { data: 'firstname', name: 'firstname' },
                { data: 'lastname', name: 'lastname' },
                { data: 'email', name: 'email' },
                { data: 'phone', name: 'phone' },
                { data: 'sacco.name', name: 'sacco.name', defaultContent: 'N/A' },
                { data: 'gender.name', name: 'gender.name' },
                { data: 'role', name: 'role' },
                { data: 'status', name: 'status' },
                { data: 'dob', name: 'dob' },
                {
                    data: 'action',
                    name: 'action',
                    orderable: true,
                    searchable: true
                },
            ]
        });
        
        var timer = null;
        $('#search-form input[name=search]').keyup(function(){
            clearTimeout(timer);
            timer = setTimeout(function(){
                table.draw();
            },1000);
        });
        $('#search-form select, #from_date, #to_date').change(function(){
            table.draw();
        });
        $('#gender').select2({
            width: '100%',
            placeholder: 'Select Gender',
            dropdownParent: $('#userModal'),
            allowClear: true,
            ajax: {
                url: '{{url("get/genders")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
        
        $('#search-gender').select2({
            width: '100%',
            placeholder: 'Select Gender',
            //dropdownParent: $('#userModal'),
            allowClear: true,
            ajax: {
                url: '{{url("get/genders")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
        
        $('#roles').select2({
            width: '100%',
            placeholder: 'Select Role',
            dropdownParent: $('#userModal'),
            allowClear: true,
            ajax: {
                url: '{{url("users/search/roles")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
        
        $('#search-roles').select2({
            width: '100%',
            placeholder: 'Select Role',
            //dropdownParent: $('#modelModal'),
            allowClear: true,
            ajax: {
                url: '{{url("users/search/roles")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
        $('.btn-launch-modal').click(function(){
            $('#userModal .modal-title span').text("New User");
            $('#userModal input[name=id]').val(0);
            $('#userModal input[name=firstname]').val("");
            $('#userModal input[name=lastname]').val("");
            $('#userModal input[name=email').val("");
            $('#userModal input[name=phone]').val("");
            $('#userModal select[name=status]').val(1);
            $('#userModal input[name=dob]').val("{{ \Carbon\Carbon::now()->subYears(18)->format('Y-m-d') }}");
            $('#roles').empty();
            $('#gender').empty();
            $('#sacco').empty();
        });
        $('#userModal .btnSave').click(function () {
            var btn = $(this);
            btn.attr('disabled', 'disabled');
            $('#userModal .feedback').removeClass('d-none');
            $('#userModal .feedback').removeClass('alert-danger');
            $('#userModal .feedback').removeClass('alert-success');
            $('#userModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
            var formData = $('#userModal form').serialize();
            $.ajax({
                url: '{{ url("users/add") }}',
                type: 'POST',
                data: formData
            }).done(function (data) {
                $('#userModal .feedback').addClass('alert-success');
                $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                table.draw();
                setTimeout(() => {
                    $('#userModal .feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            }).fail(function (response) {
                let data = response.responseJSON;
                $('#userModal .feedback').addClass('alert-danger');
                $('#userModal .feedback').html("");
                if (data.errors) {
                    if (data.errors.firstname) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.firstname + "<br>");
                    }
                    if (data.errors.lastname) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.lastname + "<br>");
                    }
                    if (data.errors.email) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.email + "<br>");
                    }
                    if (data.errors.phone) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.phone + "<br>");
                    }
                    if (data.errors.gender) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.gender + "<br>");
                    }
                    if (data.errors.role) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.role + "<br>");
                    }
                    if (data.errors.status) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                    }
                    if (data.errors.dob) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.dob + "<br>");
                    }
                } else if (data.error) {
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                } else {
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                }
                setTimeout(() => {
                    $('#userModal .feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            });
        });
        $(document).on('click', '.table .btn-edit', function(){
            $('#userModal .modal-title span').text("Edit User");
            $('#roles').empty();
            $('#countries').empty();
            var row = $(this).closest('tr');
            var id = row.find('.id').text();
            var firstname = row.find('.firstname').text();
            var lastname = row.find('.lastname').text();
            var email = row.find('.email').text();
            var phone = row.find('.phone').text();
            var status = row.find('.status').text();
            var dob = row.find('.dob').text();
            var roleId = parseInt(row.find('.role_id').text());
            var roleName = row.find('.role_name').text();
            var genderId = parseInt(row.find('.gender_id').text());
            var genderName = row.find('.gender_name').text();
            var saccoId = parseInt(row.find('.sacco_id').text());
            var sacco = row.find('.sacco').text();
            if(roleId > 0){
                var data = {
                    id: roleId,
                    text: roleName
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#roles').append(newOption).trigger('change');
            }
            if(saccoId > 0){
                var data = {
                    id: saccoId,
                    text: sacco
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#sacco').append(newOption).trigger('change');
            }else{
                $('#sacco').empty();
            }
            var data = {
                id: genderId,
                text: genderName
            };
            var newOption = new Option(data.text, data.id, false, false);
            $('#gender').append(newOption).trigger('change');

            $('#userModal input[name=id]').val(id);
            $('#userModal input[name=firstname]').val(firstname);
            $('#userModal input[name=lastname]').val(lastname);
            $('#userModal input[name=email').val(email);
            $('#userModal input[name=phone]').val(phone);
            $('#userModal select[name=status]').val(status);
            $('#userModal input[name=dob]').val(dob);
        });
    });
</script>
@endpush
