@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-user-shield'></i> Sacco</h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ url('saccos/all') }}">Saccos</a></li>
                        <li class="breadcrumb-item active">View</li>
                    </ol>
                </div><!-- /.col -->
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <article></article>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12 mb-3">
                    <div class="card card-primary card-outline">
                        <div class="card-body box-profile">
                            <div class="card-body">
                                <div class='alert border'>
                                    <h5 class="m-0"> {{$sacco->name}}</h5>
                                    <span>{{$sacco->slogan}}</span><br>
                                    <span>{{ $sacco->phone }} | {{ \Carbon\Carbon::parse($sacco->created_at)->diffForHumans() }} | <span class='badge {{ $sacco->status?'badge-primary':'badge-secondary' }}'>{{ $sacco->status?'Active':'Inactive' }}</span>
                                </div>
                                <ul class="nav nav-pills nav-fill border">
                                    <li class="nav-item">
                                        <a class="nav-link active" data-toggle="tab" href="#tabItem5"><i
                                                class="fas fa-truck mr-1"></i><span>Sacco Vehicles</span></a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-toggle="tab" href="#tabItem6"><i
                                                class="fas fa-users mr-1"></i><span>Members</span></a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-toggle="tab" href="#tabItem7"><i
                                                class="fas fa-road mr-1"></i><span>Routes</span></a>
                                    </li>
                                </ul>
                                <div class="tab-content mt-4">
                                    <div class="tab-pane active" id="tabItem5">
                                        <div class='row mb-2'>
                                            <div class='col-8 col-sm-8'>
                                                <form class='search-form-member'>
                                                    <input type='text' name='search' class='form-control'
                                                           placeholder="Search">
                                                </form>
                                            </div>
                                            <div class='col-4 col-sm-4 text-right'>
                                                <button class='btn btn-primary btn-sm' data-toggle="modal"
                                                        data-target='#vehicleModal'>Add Vehicle
                                                </button>
                                            </div>
                                        </div>
                                        <div class='table-responsive'>
                                            <table
                                                class="table_sacco_vehicles table table-striped vehicles w-100">
                                                <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Vehicle</th>
                                                    <th>Till</th>
                                                    <th>Merchant</th>
                                                    <th>Fleet No</th>
                                                    <th>Status</th>
                                                    <th class='text-right notexport'>Action</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                </tbody>
                                            </table>

                                        </div>
                                    </div>
                                    <div class="tab-pane" id="tabItem6">
                                        <div class='row'>
                                            <div class='col col-sm-8 text-right'>
                                                <button class='btn btn-primary' data-toggle="modal"
                                                        data-target='#memberModal'>Add Member
                                                </button>
                                            </div>
                                            <div class='col col-sm-4'>
                                                <form class='search-form-member'>
                                                    <input type='text' name='search' class='form-control'
                                                           placeholder="Search">
                                                </form>
                                            </div>
                                        </div>
                                        <div class='table-responsive'>
                                            <table
                                                class="table_sacco_users align-items-center table-flush members w-100">
                                                <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>First Name</th>
                                                    <th>Last Name</th>
                                                    <th>Email</th>
                                                    <th>Phone</th>
                                                    <th>Status</th>
                                                    <th>Joined At</th>
                                                    <th class='text-right notexport'>Action</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                </tbody>
                                            </table>

                                        </div>
                                    </div>
                                    <div class="tab-pane" id="tabItem7">
                                        <div class='row'>
                                            <div class='col col-sm-8 text-right'>
                                                <button class='btn btn-primary' data-toggle="modal"
                                                        data-target='#routeModal'>Add Route
                                                </button>
                                            </div>
                                            <div class='col col-sm-4'>
                                                <form class='search-form-routes'>
                                                    <input type='text' name='search' class='form-control'
                                                           placeholder="Search">
                                                </form>
                                            </div>
                                        </div>
                                        <div class='table-responsive'>
                                            <table
                                                class="table_sacco_routes align-items-center table-flush routes w-100">
                                                <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Name</th>
                                                    <th>From</th>
                                                    <th>To</th>
                                                    <th>Date</th>
                                                    <th class='text-right notexport'>Action</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                </tbody>
                                            </table>

                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>


                </div>
            </div>
        </div>
    </section>

    <div class="modal fade" id="vehicleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
         aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="border-bottom: 1px solid rgb(200,200,200);">
                    <h5 class="modal-title" id="exampleModalLabel" style='font-weight: 600 !important;'>Add Vehicle to
                        sacco</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="border-bottom: 1px solid rgb(200,200,200);">
                    <!-- Place to show the response message -->
                    <div id="responseMessage" class="alert" style="display: none;"></div>

                    <form action="{{url('sacco/vehicle/add')}}" method="POST" class="row">
                        @csrf
                        <div class="col-sm-12 form-group sacco">
                            <input type="hidden" name="id" class="form-control" value="0">
                            <input type="hidden" name="sacco_id" class="form-control" value="{{$sacco->id}}">
                            <label>Vehicle Plate/Merchant/Till</label>
                            <select id='vehicle' name="vehicle" class='form-control'></select>
                        </div>
                    </form>
                </div>

                <div class="modal-footer pt-2 pb-2">
                    <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btnSaveVehicle">Save changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="memberModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
         aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="border-bottom: 1px solid rgb(200,200,200);">
                    <h5 class="modal-title" id="exampleModalLabel" style="font-weight: 600;">Add Member</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="border-bottom: 1px solid rgb(200,200,200);">
                    <form action="{{url('/sacco/member/add')}}" method="POST" class="row">
                        @csrf
                        <div class="col-sm-12 form-group sacco">
                            <input type="hidden" name="id" class="form-control" value="0">
                            <input type="hidden" name="sacco_id" class="form-control" value="{{$sacco->id}}">
                            <label>Member Name/Email</label>
                            <select id='member' name="member" class='form-control'></select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer pt-2 pb-2">
                    <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btnSaveMember">Save changes</button>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="routeModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
         aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="border-bottom: 1px solid rgb(200,200,200);">
                    <h5 class="modal-title" id="exampleModalLabel" style="font-weight: 600;">Add Route</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="border-bottom: 1px solid rgb(200,200,200);">
                    <form action="{{url('/sacco/route/add')}}" method="POST" class="row">
                        @csrf
                        <div class="col-sm-12 form-group sacco">
                            <input type="hidden" name="id" class="form-control" value="0">
                            <input type="hidden" name="sacco_id" class="form-control" value="{{$sacco->id}}">
                            <label>Route</label>
                            <select id='route' name="route" class='form-control'></select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer pt-2 pb-2">
                    <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btnSaveRoute">Save changes</button>
                </div>
            </div>
        </div>
    </div>
    </div>


@endsection
@push('js')
    <script>
        $(document).ready(function () {

            var timer = null;

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('datatable/saccos') }}",
                    data: function (d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('input[name=from_date]').val();
                        d.from_time = $('input[name=from_time]').val();
                        d.to_date = $('input[name=to_date]').val();
                        d.to_time = $('input[name=to_time]').val();
                        d.status = $('select[name=status]').val();
                        d.d = $('select[name=d]').val();
                        d.is_datable = true;
                    }
                },
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'name', name: 'name', orderable: false},
                    {data: 'slogan', name: 'slogan'},
                    {data: 'phone', name: 'phone'},
                    {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<button class="btn btn-success">Active</button>';
                                case 2:
                                    return '<button class="btn btn-danger">Inactive</button>';
                                case 3:
                                    return '<button class="btn btn-secondary">Suspended</button>';
                                default:
                                    return '<button class="btn btn-secondary">Invalid</button>';
                            }
                        }
                    },
                    {data: 'created_at', name: 'created_at'},
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });

            var table_sacco_vehicles = $('.table_sacco_vehicles').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('/datatable/sacco/vehicles/'.$sacco->id) }}",
                    data: function (d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('input[name=from_date]').val();
                        d.from_time = $('input[name=from_time]').val();
                        d.to_date = $('input[name=to_date]').val();
                        d.to_time = $('input[name=to_time]').val();
                        d.status = $('select[name=status]').val();
                        d.d = $('select[name=d]').val();
                    }
                },
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'vehicle_id.plate', name: 'plate', orderable: false},
                    {data: 'vehicle_id.till_number', name: 'till_number', orderable: false},
                    {data: 'vehicle_id.merchant_short_code', name: 'merchant_short_code', orderable: false},
                    {data: 'vehicle_id.fleet_no', name: 'fleet_no', orderable: false},
                    {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<button class="btn btn-success">Active</button>';
                                case 2:
                                    return '<button class="btn btn-danger">Inactive</button>';
                                case 3:
                                    return '<button class="btn btn-secondary">Suspended</button>';
                                default:
                                    return '<button class="btn btn-secondary">Invalid</button>';
                            }
                        }
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });
            var table_sacco_users = $('.table_sacco_users').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('/datatable/sacco/users/'.$sacco->id) }}",
                    data: function (d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('input[name=from_date]').val();
                        d.from_time = $('input[name=from_time]').val();
                        d.to_date = $('input[name=to_date]').val();
                        d.to_time = $('input[name=to_time]').val();
                        d.status = $('select[name=status]').val();
                        d.d = $('select[name=d]').val();
                    }
                },
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'user_id.firstname', name: 'firstname', orderable: false},
                    {data: 'user_id.lastname', name: 'lastname', orderable: false},
                    {data: 'user_id.email', name: 'email', orderable: false},
                    {data: 'user_id.phone', name: 'phone', orderable: false},
                    {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<button class="btn btn-success">Active</button>';
                                case 2:
                                    return '<button class="btn btn-danger">Inactive</button>';
                                case 3:
                                    return '<button class="btn btn-secondary">Suspended</button>';
                                default:
                                    return '<button class="btn btn-secondary">Invalid</button>';
                            }
                        }
                    },
                    {data: 'created_at', name: 'created_at'},
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });
            var table_sacco_routes = $('.table_sacco_routes').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('/datatable/sacco/routes/'.$sacco->id) }}",
                    data: function (d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('input[name=from_date]').val();
                        d.from_time = $('input[name=from_time]').val();
                        d.to_date = $('input[name=to_date]').val();
                        d.to_time = $('input[name=to_time]').val();
                        d.status = $('select[name=status]').val();
                        d.d = $('select[name=d]').val();
                    }
                },
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'route_id.name', name: 'name', orderable: false},
                    {data: 'route_id.from_id.name', name: 'from', orderable: false},
                    {data: 'route_id.to_id.name', name: 'to', orderable: false},
                    {
                        data: 'created_at',
                        name: 'created_at',
                        orderable: false,
                        render: function (data, type, row) {
                            var date = new Date(data);
                            return date.toLocaleString('en-US', {
                                month: 'long',
                                day: 'numeric',
                                year: 'numeric',
                                hour: 'numeric',
                                minute: 'numeric',
                                hour12: true
                            });
                        }
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });

            $('#search-form').on('submit', function (e) {
                e.preventDefault();

                var formData = $(this).serialize() + "&_token=" + $('meta[name="csrf-token"]').attr('content');

                $.ajax({
                    url: "{{ url('/datatable/sacco/vehicles/'.$sacco->id) }}",
                    type: "GET",
                    data: formData,
                    success: function (response) {
                        table_sacco_vehicles.draw();
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        console.log(textStatus, errorThrown);
                    }
                });
            });

            $('#search-form').on('submit', function (e) {
                e.preventDefault();

                var formData = $(this).serialize() + "&_token=" + $('meta[name="csrf-token"]').attr('content');

                $.ajax({
                    url: "{{ url('datatable/saccos') }}",
                    type: "GET",
                    data: formData,
                    success: function (response) {
                        table.draw();
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        console.log(textStatus, errorThrown);
                    }
                });
            });

            $('.btn-launch-modal').click(function () {
                $('#saccoModal .modal-title span').text("New ");
                $('#saccoModal input[name=id]').val(0);
                $('#saccoModal input[name=name]').val("");
                $('#saccoModal input[name=slogan]').val("");
                $('#saccoModal input[name=phone]').val("");
                $('#saccoModal input[name=status]').val("");
            });

            $('#saccoModal .btnSave').click(function () {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#saccoModal .feedback').removeClass('d-none');
                $('#saccoModal .feedback').removeClass('alert-danger');
                $('#saccoModal .feedback').removeClass('alert-success');
                $('#saccoModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#saccoModal form').serialize();
                $.ajax({
                    url: '{{ url("sacco/add") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('#saccoModal .feedback').addClass('alert-success');
                    $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#saccoModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('#saccoModal .feedback').addClass('alert-danger');
                    $('#saccoModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                        }
                    } else if (data.error) {
                        $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('#saccoModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });


            $(document).on('click', '.table .btn-edit', function () {
                $('#saccoModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var slogan = row.find('.slogan').text();
                var phone = row.find('.phone').text();
                var status = row.find('.status').text();

                $('#saccoModal input[name=id]').val(id);
                $('#saccoModal input[name=name]').val(name);
                $('#saccoModal input[name=slogan]').val(slogan);
                $('#saccoModal input[name=phone]').val(phone);
                $('#saccoModal input[name=status]').val(status);
            });

            $('#vehicleModal input[name="name"]').keyup(function () {
                console.log('Called');

                $("#vehicleModal .sacco .list-group").show();
                clearTimeout(timer);
                console.log(timer);

                timer = setTimeout(function () {
                    var search = $('#vehicleModal .sacco input[name="name"]').val();
                    $("#vehicleModal .sacco .list-group").html("<p class='list-group-item'><i class='fas fa-spinner fa-pulse'></i> Please wait...</p>");
                    searchCars(search);
                }, 1000);
            });

            $('.btnSaveVehicle').click(function () {
                $('#vehicleModal form').submit();
            })

            ;$('.btnSaveMember').click(function () {
                $('#memberModal form').submit();
            });

            $('.btnSaveRoute').click(function () {
                $('#routeModal form').submit();
            });


            $('#vehicle').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                dropdownParent: $('#vehicleModal'),
                allowClear: true,
                ajax: {
                    url: "{{url('vehicles/search')}}",
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.plate,
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true

                }
            });

            $('#member').select2({
                width: '100%',
                placeholder: 'Select Member',
                dropdownParent: $('#memberModal'),
                allowClear: true,
                ajax: {
                    url: "{{url('/users/search/users')}}",
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.email,
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true

                }
            });

            $('#route').select2({
                width: '100%',
                placeholder: 'Select Route',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: "{{url('/routes/search/route')}}",
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

            $('.btnSaveVehicle').on('click', function (event) {
                event.preventDefault();

                var form = $(this).closest('form');
                var formData = form.serialize();

                $.ajax({
                    type: 'GET',
                    url: form.attr('action'),
                    data: formData,
                    success: function (response) {
                        // Close the modal
                        $('#vehicleModal').modal('hide');

                        // Clear the form
                        form[0].reset();

                        // Display a success message to the user
                        var msgDiv = $('#responseMessage');
                        msgDiv.removeClass('alert-danger').addClass('alert-success').text('Vehicle added successfully').show();

                        // Hide the message after 3 seconds
                        setTimeout(function () {
                            msgDiv.hide();
                        }, 3000);

                        // Update the page with the new data (if necessary)
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        // Display an error message to the user
                        var msgDiv = $('#responseMessage');
                        msgDiv.removeClass('alert-success').addClass('alert-danger').text('An error occurred: ' + errorThrown).show();
                        event.preventDefault();

                        // Hide the message after 3 seconds
                        setTimeout(function () {
                            msgDiv.hide();
                        }, 300000);
                    }
                });
            });

            $('.btnSaveMember').on('click', function (event) {
                event.preventDefault();

                var form = $(this).closest('form');
                var formData = form.serialize();

                $.ajax({
                    type: 'GET',
                    url: form.attr('action'),
                    data: formData,
                    success: function (response) {
                        // Close the modal
                        $('#memberModal').modal('hide');

                        // Clear the form
                        form[0].reset();

                        // Display a success message to the user
                        var msgDiv = $('#responseMessage');
                        msgDiv.removeClass('alert-danger').addClass('alert-success').text('Member added successfully').show();

                        // Hide the message after 3 seconds
                        setTimeout(function () {
                            msgDiv.hide();
                        }, 3000);

                        // Update the page with the new data (if necessary)
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        // Display an error message to the user
                        var msgDiv = $('#responseMessage');
                        msgDiv.removeClass('alert-success').addClass('alert-danger').text('An error occurred: ' + errorThrown).show();
                        event.preventDefault();

                        // Hide the message after 3 seconds
                        setTimeout(function () {
                            msgDiv.hide();
                        }, 300000);
                    }
                });
            });

            $('.btnSaveRoute').on('click', function (event) {
                event.preventDefault();

                var form = $(this).closest('form');
                var formData = form.serialize();

                $.ajax({
                    type: 'GET',
                    url: form.attr('action'),
                    data: formData,
                    success: function (response) {
                        // Close the modal
                        $('#routeModal').modal('hide');

                        // Clear the form
                        form[0].reset();

                        // Display a success message to the user
                        var msgDiv = $('#responseMessage');
                        msgDiv.removeClass('alert-danger').addClass('alert-success').text('Member added successfully').show();

                        // Hide the message after 3 seconds
                        setTimeout(function () {
                            msgDiv.hide();
                        }, 3000);

                        // Update the page with the new data (if necessary)
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        // Display an error message to the user
                        var msgDiv = $('#responseMessage');
                        msgDiv.removeClass('alert-success').addClass('alert-danger').text('An error occurred: ' + errorThrown).show();
                        event.preventDefault();

                        // Hide the message after 3 seconds
                        setTimeout(function () {
                            msgDiv.hide();
                        }, 300000);
                    }
                });
            });


        });


    </script>
@endpush
