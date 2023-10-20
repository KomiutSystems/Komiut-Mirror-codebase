@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-flag'></i> Queues</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Queues')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                        data-target="#routeModal"><i
                        class='fas fa-plus'></i> Add Queue</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Queues</li>
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
                                    <div class="col-sm-4">
                                        <label>Search</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Route</label>
                                        <select class="form-control mb-1" name="route" id='search-route'>
                                        </select>
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Terminus</label>
                                        <select class="form-control mb-1" name="terminus" id='search-terminus'>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>Sacco</label>
                                        <select class="form-control mb-1" name="sacco" id='search-sacco'>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>Status</label>
                                        <select name="status" class="form-control mb-1" id='search-status'>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>From Date</label>
                                        <input type="text" class="form-control mb-1" id="from_date" name="from_date" placeholder='From Date' value='{{ Carbon\Carbon::today() }}'>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>To Date</label>
                                        <input type="text" class="form-control mb-1" id="to_date" name="to_date" placeholder='To Date' value='{{ Carbon\Carbon::today()->format('Y-m-d') }} 23:59'>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>QN</th>
                                        <th>Vehicle</th>
                                        <th>Terminus</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Sacco</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>User</th>
                                        <th>Status</th>
                                        <th>Date</th>
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
    <div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Queue</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('queues/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>

                        <div class='col-sm-12 form-group'>
                            <label>Vehicle</label>
                            <select name="vehicle" class='form-control' id='vehicle'>
                            </select>
                        </div>
                        <div class="col-sm-12">
                            <label>Route</label>
                            <select name="route" class="form-control mb-1" id='route'>
                            </select>
                        </div>
                        <div class="col-sm-12">
                            <label>Terminus</label>
                            <select name="terminus" class="form-control mb-1" id='terminus'>
                            </select>
                        </div>
                        <div class="col-sm-12">
                            <label>Amount</label>
                            <input type='number' name="amount" class="form-control mb-1" placeholder="amount" required>
                            </select>
                        </div>
                        <div class='col-sm-12'>
                            <div class='alert border'>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="choice" id="choice1" value="0" checked>
                                    <label class="form-check-label" for="choice1">Instant Queue</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="choice" id="choice2" value="1">
                                    <label class="form-check-label" for="choice2">Scheduled Queue</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12 schedule_time d-none">
                            <label>Departure Time</label>
                            <input type='date' id='schedule_time' name='schedule_time' class='form-control' placeholder="Departure Time"/>
                        </div>
                        <div class="col-sm-12">
                            <label>Status</label>
                            <select name="status" class="form-control mb-1" id='status'>
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
        $(document).ready(function () {
            
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            flatpickr("#schedule_time", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                minDate: "{{ date('Y-m-d H:i') }}"
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
            if(sacco_id > 0){
                var data = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#search-sacco').append(newOption).trigger('change');
            }
            $('#vehicle').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("vehicles/search")}}',
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
        
            $('#route').select2({
                width: '100%',
                placeholder: 'Select Route',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("routes/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.from.name+" - "+item.to.name+' ('+item.name+')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            $('#search-route').select2({
                width: '100%',
                placeholder: 'Select Route',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("routes/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.from.name+" - "+item.to.name+' ('+item.name+')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            $('#terminus').select2({
                width: '100%',
                placeholder: 'Select Terminus',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("routes/termini/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.name+' ('+item.place.name+')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            $('#search-terminus').select2({
                width: '100%',
                placeholder: 'Select Terminus',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("routes/termini/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.name+' ('+item.place.name+')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            $('#status').select2({
                width: '100%',
                placeholder: 'Select Status',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("queues/statuses/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.name+' ('+item.status+')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            $('#search-status').select2({
                width: '100%',
                placeholder: 'Select Status',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("queues/statuses/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.name+' ('+item.status+')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('queues/datatable/queues') }}",
                    data: function (d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.route = $('.search-form select[name=route]').val();
                        d.status = $('.search-form select[name=status]').val();
                        d.terminus = $('.search-form select[name=terminus]').val();
                        d.sacco = $('.search-form select[name=sacco]').val();
                        d.from_date = $('.search-form input[name=from_date]').val();
                        d.to_date = $('.search-form input[name=to_date]').val();
                    }
                },

                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'queue_number', name: 'queue_number', orderable: false, searchable: false},
                    {data: 'vehicle.plate', name: 'vehicle.plate', orderable: false, searchable: false},
                    {data: 'terminus.name', name: 'terminus.name', orderable: false, searchable: false, defaultContent:'N/A'},
                    {data: 'route.from.name', name: 'route.from.name',orderable: false, searchable: false},
                    {data: 'route.to.name', name: 'route.to.name',orderable: false, searchable: false},
                    {data: 'vehicle.sacco.name', name: 'vehicle.sacco.name', defaultContent:'N/A',orderable: false, searchable: false},
                    {data: 'start_time', name: 'start_time',orderable: false, searchable: false},
                    {data: 'stop_time', name: 'stop_time', defaultContent:'N/A',orderable: false, searchable: false},
                    {
                        data: null, 
                        render: function (data, type, row) {
                            return row.user.firstname + ' ' + row.user.lastname;
                        },orderable: false, searchable: false
                    }, 
                    {
                        data: 'queue_status',
                        name: 'name',
                        render: function (data, type, row) {
                            switch (data.status) {
                                case "Pending":
                                    return '<span class="badge bg-secondary">'+data.name+'</span>';
                                case "Active":
                                    return '<span class="badge bg-primary">'+data.name+'</span>';
                                case "Suspended":
                                    return '<span class="badge bg-warning">'+data.name+'</span>';
                                case "Completed":
                                    return '<span class="badge bg-success">'+data.name+'</span>';
                                default:
                                    return '<span class="badge bg-danger">'+data.name+'</span>';
                            }
                        },orderable: false, searchable: false
                    },
                    {data: 'created_at', name: 'created_at',orderable: false, searchable: false},
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });
            var timer = null;

            $('#search-form input[type=text]').keyup(function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    table.draw();
                }, 1000);
            });

            $('#search-form input[name=from_date], #search-form input[name=to_date]').change(function () {
                table.draw();
            });
            $('#search-form select').change(function(){
                table.draw();
            });
            $('form input[type=radio]').change(function(){
                var value = $(this).val();
                if(value == 1){
                    $('#routeModal .schedule_time').removeClass('d-none');
                }else{
                    $('#routeModal .schedule_time').addClass('d-none'); 
                }
            });
            $('.btn-launch-modal').click(function () {
                $('#routeModal .modal-title span').text("New ");
                $('#routeModal input[name=id]').val(0);
                $('#vehicle').val(null).trigger('change');
                $('#route').val(null).trigger('change');
                $('#terminus').val(null).trigger('change');
                $('#status').val(null).trigger('change');
                $('#routeModal input[name=amount]').val("");
                $('#routeModal input[name=schedule_time]').val("");
                $("#choice1").prop("checked", true);
                $('#routeModal .schedule_time').addClass('d-none'); 
            });

            $('#routeModal .btnSave').click(function () {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#routeModal .feedback').removeClass('d-none');
                $('#routeModal .feedback').removeClass('alert-danger');
                $('#routeModal .feedback').removeClass('alert-success');
                $('#routeModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#routeModal form').serialize();
                $.ajax({
                    url: '{{ url("queues/add") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('#routeModal .feedback').addClass('alert-success');
                    $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#routeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('#routeModal .feedback').addClass('alert-danger');
                    $('#routeModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.id) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.id + "<br>");
                        }
                        if (data.errors.vehicle) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.vehicle + "<br>");
                        }
                        if (data.errors.terminus) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.terminus + "<br>");
                        }
                        if (data.errors.choice) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.choice + "<br>");
                        }
                        if (data.errors.schedule_time) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.schedule_time + "<br>");
                        }
                        if (data.errors.amount) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.amount + "<br>");
                        }
                        if (data.errors.status) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                        }
                    } else if (data.error) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('#routeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function () {
                $('#routeModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var vehicle = row.find('.vehicle').text();
                var vehicle_id = row.find('.vehicle_id').text();
                var route = row.find('.route').text();
                var route_id = row.find('.route_id').text();
                var terminus = row.find('.terminus').text();
                var terminus_id = row.find('.terminus_id').text();
                var status = row.find('.status').text();
                var status_id = row.find('.status_id').text();
                var amount = row.find('.amount').text();
                var schedule_time = row.find('.schedule_time').text();
                var queue_type = row.find('.queue_type').text();
                if(queue_type == 1){
                    $("#choice2").prop("checked", true);
                    $('#routeModal .schedule_time').removeClass('d-none'); 
                }else{
                    $("#choice1").prop("checked", true);
                    $('#routeModal .schedule_time').addClass('d-none'); 
                }
            
                $('#routeModal input[name=id]').val(id);
                
                var data = {
                    id: vehicle_id,
                    text: vehicle
                };                
                var newOption = new Option(data.text, data.id, false, false);
                $('#vehicle').append(newOption).trigger('change');
                
                var data = {
                    id: route_id,
                    text: route
                };                
                var newOption = new Option(data.text, data.id, false, false);
                $('#route').append(newOption).trigger('change');
                
                var data = {
                    id: route_id,
                    text: route
                };                
                var newOption = new Option(data.text, data.id, false, false);
                $('#route').append(newOption).trigger('change');
                
                var data = {
                    id: terminus_id,
                    text: terminus
                };                
                var newOption = new Option(data.text, data.id, false, false);
                $('#terminus').append(newOption).trigger('change');
                
                var data = {
                    id: status_id,
                    text: status
                };                
                var newOption = new Option(data.text, data.id, false, false);
                $('#status').append(newOption).trigger('change');

                $('#routeModal input[name=amount]').val(amount);
                $('#routeModal input[name=schedule_time]').val(schedule_time);
        });

    });
    </script>
@endpush
