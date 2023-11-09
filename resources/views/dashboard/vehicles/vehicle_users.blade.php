@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h4 class="m-0"><i class='fas fa-id-card'></i> <b>Vehicle</b> Users</h4>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Vehicles')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#vehicleModal"><i
                                class='fas fa-plus'></i> Add Vehicle User
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Vehicle Users</li>
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
                            <div class="card-body">
                                <form class='search-form row' id='search-form'>
                                    <div class="col-sm-3">
                                        <label>Search</label>
                                        <input type="text" class="form-control mb-1" name="search" placeholder="Search">
                                    </div>
                                    <div class="col-sm-3">
                                        <label>Sacco</label>
                                        <select name="sacco" class="form-control mb-1" id='search-sacco'>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>From Date</label>
                                        <input type="text" class="form-control mb-1" id="from_date" name="from_date"
                                            placeholder='From Date' value='{{ Carbon\Carbon::today() }}'>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>To Date</label>
                                        <input type="text" class="form-control mb-1" id="to_date" name="to_date"
                                            placeholder='To Date'
                                            value='{{ Carbon\Carbon::today()->format('Y-m-d') }} 23:59'>
                                    </div>
                                    <!--
                                        <div class="col-sm-3">
                                            <label>Status</label>
                                            <select name="status" class="form-control mb-1">
                                                <option value="1">Active</option>
                                                <option value="0">Inactive</option>
                                            </select>
                                        </div>-->
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>phone</th>
                                            <th>role</th>
                                            <th>Vehicle</th>
                                            <th>Sacco</th>
                                            <th>Start</th>
                                            <th>End</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                            <th class='text-end notexport'>Action</th>
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
    <div class="modal fade" id="vehicleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Vehicle User</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('vehicle/user/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class="col-sm-12 form-group">
                            <label>User</label>
                            <select name="user" class="form-control mb-1" id='user'>
                            </select>
                        </div>
                        <div class="col-sm-12 form-group">
                            <label>Vehicle</label>
                            <select name="vehicle" class="form-control mb-1" id='vehicle'>
                            </select>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Status</label>
                            <select name="status" class='form-control'>
                                <option value='1'>Active</option>
                                <option value='0'>Inactive</option>
                            </select>
                        </div>
                        <div class='alert feedback border d-none'>
                            <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><i
                            class='fas fa-times'></i> Close
                    </button>
                    <button type="button" class="btn btn-primary btn-sm btnSave"><i class='fas fa-paper-plane'></i> Save
                        changes
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('js')
    <script>
        $(document).ready(function() {
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            var sacco_id = "{{ $sacco != null?$sacco->id:0 }}";
            var sacco = "{{ $sacco != null?$sacco->name:0 }}";
            $('#user').select2({
                width: '100%',
                placeholder: 'Select User',
                dropdownParent: $('#vehicleModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('users/search/users') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.firstname + ' ' + item.lastname + ' ( ' + item
                                        .email + '|' + item.phone + ' )',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            $('#vehicle').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                dropdownParent: $('#vehicleModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('vehicles/search') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.plate + ' ( ' + item.till_number + '|' + item
                                        .merchant_short_code + ' )',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            $('#search-sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#vehicleModal'),
                allowClear: sacco_id>0?false:true,
                ajax: {
                    url: '{{ url('saccos/search') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
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
                var newOption = new Option(data.text, data.id, false, false);
                $('#search-sacco').append(newOption).trigger('change');
            }


            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('vehicles/datatable/users') }}",
                    data: function(d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('#search-form input[name=from_date]').val();
                        d.to_date = $('#search-form input[name=to_date]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                    }
                },
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: 'Vehicle Users',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: 'Vehicle Users',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: 'Vehicle Users',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [ [20, 100, 250, 500, 1000], [20,100, 250, 500, 1000] ],
                dom: 'lBtrip',
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    //{data: 'user.firstname', name: 'user.firstname'},
                    {
                        data: null,
                        render: function(data, type, row) {
                            return row.user.firstname + ' ' + row.user.lastname;
                        },
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'user.email',
                        name: 'user.email',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'user.phone',
                        name: 'user.phone',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'role',
                        name: 'role',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'vehicle.plate',
                        name: 'vehicle.plate',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'sacco.name',
                        name: 'sacco.name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'start_date',
                        name: 'start_date',
                        orderable:false,
                        searchable: false,
                    },
                    {
                        data: 'end_date',
                        name: 'end_date',
                        orderable:false,
                        searchable: false,
                    },
                    {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge badge-primary">Active</span>';
                                default:
                                    return '<span class="badge badge-secondary">Inactive</span>';
                            }
                        }
                    },
                    {
                        data: 'created_at',
                        name: 'created_at'
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });
            var timer = null;
            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            });
            $('#search-form select, #from_date, #to_date').change(function() {
                table.draw();
            });
            $('#search-form').on('submit', function(e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function() {
                $('#vehicleModal .modal-title span').text("New ");
                $('#vehicleModal input[name=id]').val(0);
                //$('#seat').val(null).trigger("change");
                $('#user').empty();
                $('#vehicle').empty();
                $('#vehicleModal input[name=status]').val(1);
            });
            $('#vehicleModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#vehicleModal .feedback').removeClass('d-none');
                $('#vehicleModal .feedback').removeClass('alert-danger');
                $('#vehicleModal .feedback').removeClass('alert-success');
                $('#vehicleModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#vehicleModal form').serialize();
                $.ajax({
                    url: '{{ url('vehicles/user/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#vehicleModal .feedback').addClass('alert-success');
                    $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#vehicleModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#vehicleModal .feedback').addClass('alert-danger');
                    $('#vehicleModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.vehicle) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .vehicle + "<br>");
                        }
                        if (data.errors.user) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .user + "<br>");
                        }
                        if (data.errors.status) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .status + "<br>");
                        }
                    } else if (data.error) {
                        $('#vehicleModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#vehicleModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                            );
                    }
                    setTimeout(() => {
                        $('#vehicleModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function() {
                $('#user').empty();
                $('#vehicle').empty();
                $('#vehicleModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var vehicle = row.find('.vehicle').text();
                var vehicle_id = row.find('.vehicle_id').text();
                var user_id = row.find('.user_id').text();
                var user = row.find('.user').text();
                var status = row.find('.status').text();

                $('#vehicleModal input[name=id]').val(id);
                $('#vehicleModal input[name=status]').val(status);
                if (vehicle_id > 0) {
                    var data = {
                        id: vehicle_id,
                        text: vehicle
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#vehicle').append(newOption).trigger('change');
                }
                if (user_id > 0) {
                    var data = {
                        id: user_id,
                        text: user
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#user').append(newOption).trigger('change');
                }
            });

        });
    </script>
@endpush
