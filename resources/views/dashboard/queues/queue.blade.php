@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-flag'></i> <b>View</b> Queue</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ url('queues/all') }}">Queues</a></li>
                        <li class="breadcrumb-item active">View</li>
                    </ol>
                    <!--
                                    <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                                    data-target="#routeModal"><i
                                    class='fas fa-plus'></i> Add Parcel
                                    </button>
                                    <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                                    data-target="#routeModal"><i
                                    class='fas fa-plus'></i> Add Passenger
                                    </button>-->
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
                                <div class="row">
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Queue Number</label><br>
                                        {{ $queue->queue_number }}
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Plate</label><br>
                                        {{ $queue->vehicle->plate }}
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Fleet No</label><br>
                                        {{ $queue->vehicle->fleet_no }}
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Terminus</label><br>
                                        {{ $queue->terminus->name }}
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Route</label><br>
                                        {{ $queue->route->from->name }} - {{ $queue->route->to->name }}
                                        ({{ $queue->route->name }})
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Sacco</label><br>
                                        {{ $queue->vehicle->sacco->name }}
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Status</label><br>
                                        {{ $queue->queue_status->name }}
                                    </div>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-3">
                                        <label>Queue By</label><br>
                                        {{ $queue->user->firstname }} {{ $queue->user->lastname }}
                                    </div>
                                    <div class="col-12 p-3">
                                        <div class='alert alert-info'>
                                            Fare Amount From <b>{{ $queue->route->from->name }}</b> to
                                            <b>{{ $queue->route->to->name }}</b> is
                                            {{ number_format($queue->amount, 2, '.', ',') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- small box -->
                    <div class="card card-primary card-outline">
                        <div class="card-body box-profile">
                            <div class="card-body">

                                <!-- Nav tabs -->
                                <ul class="nav nav-pills nav-fill border" id="myTab" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="passengers-tab" data-toggle="tab" href="#passengers"
                                            role="tab" aria-controls="passengers" aria-selected="true">
                                            <i class='fas fa-users'></i> Passengers
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="parcels-tab" data-toggle="tab" href="#parcels"
                                            role="tab" aria-controls="parcels" aria-selected="false">
                                            <i class='fas fa-parcel'></i> Parcels
                                        </a>
                                    </li>
                                </ul>

                                <!-- Tab panes -->
                                <div class="tab-content">
                                    <!-- passenger-->
                                    <div class="tab-pane active pt-4" id="passengers" role="tabpanel"
                                        aria-labelledby="passengers-tab">
                                        <div class='alert border text-right'>
                                            @can('Add Passengers')
                                                <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                                                    data-target="#routeModal"><i class='fas fa-plus'></i> Add Passenger
                                                </button>
                                            @endcan
                                        </div>
                                        <form class='search-form row d-flex align-items-end' id='search-form'>
                                            <div class="col-sm-6">
                                                <label>Search</label>
                                                <input type="text" class="form-control mb-1" name="search"
                                                    placeholder="Search">
                                            </div>
                                            <div class="col-sm-6">
                                                <label>Status</label>
                                                <select name="status" class="form-control mb-1" id='search-status'>
                                                </select>
                                            </div>
                                        </form>

                                        <div class="table-responsive">
                                            <table class='table passengers'>
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Name</th>
                                                        <th>phone</th>
                                                        <th>From</th>
                                                        <th>To</th>
                                                        <th>Amount</th>
                                                        <th>Passengers</th>
                                                        <th>Start</th>
                                                        <th>End</th>
                                                        <th>User</th>
                                                        <th>Paid</th>
                                                        <th>Boarded</th>
                                                        <th>Status</th>
                                                        <th>Date</th>
                                                        <th class='text-end notexport'>Action</th>
                                                    </tr>
                                                </thead>
                                            </table>
                                        </div>
                                    </div>
                                    <!--end passengers-->
                                    <!--parcel tab-->
                                    <div class="tab-pane pt-4" id="parcels" role="tabpanel" aria-labelledby="parcels-tab">
                                        <form class='search-form row d-flex align-items-end' id='search-form'>
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
                                            <div class="col-sm-4">
                                                <label>Status</label>
                                                <select name="status" class="form-control mb-1" id='search-status'>
                                                </select>
                                            </div>
                                            <div class="col-sm-4">
                                                <label>From Date</label>
                                                <input type="text" class="form-control mb-1" id="from_date"
                                                    name="from_date" placeholder='From Date'
                                                    value='{{ Carbon\Carbon::today() }}'>
                                            </div>
                                            <div class="col-sm-4">
                                                <label>To Date</label>
                                                <input type="text" class="form-control mb-1" id="to_date"
                                                    name="to_date" placeholder='To Date'
                                                    value='{{ Carbon\Carbon::today()->format('Y-m-d') }} 23:59'>
                                            </div>
                                        </form>

                                        <div class="table-responsive">
                                            <table class='table parcels'>
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>name</th>
                                                        <th>phone</th>
                                                        <th>From</th>
                                                        <th>To</th>
                                                        <th>Start</th>
                                                        <th>End</th>
                                                        <th>User</th>
                                                        <th>Status</th>
                                                        <th>Date</th>
                                                        <th class='text-end notexport'>Action</th>
                                                    </tr>
                                                </thead>
                                            </table>
                                        </div>
                                    </div>
                                    <!-- end parcels tab-->
                                </div>
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
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Passenger</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('queues/passenger/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <input type='hidden' name='queue_id' value='{{ $queue->id }}'>

                        <div class='col-sm-6 col-md-4 form-group'>
                            <label>Name</label>
                            <input type='text' class='form-control' name='name' placeholder="Name" />
                        </div>

                        <div class='col-sm-6  col-md-4 form-group'>
                            <label>Phone</label>
                            <input type='text' class='form-control' name='phone' placeholder="Phone" />
                        </div>

                        <div class='col-sm-6  col-md-4 form-group'>
                            <label>Vehicle</label>
                            <input class='form-control' value='{{ $queue->vehicle->plate }}' name='plate' readonly />
                        </div>

                        <div class='col-sm-6  col-md-4 form-group'>
                            <label>Route</label>
                            <input class='form-control'
                                value='{{ $queue->route->from->name }} - {{ $queue->route->to->name }}' name='route'
                                readonly />
                        </div>

                        <div class="col-sm-6  col-md-4">
                            <label>From</label>
                            <select name="from" class="form-control mb-1" id='from'>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-4">
                            <label>To</label>
                            <select name="to" class="form-control mb-1" id='to'>
                            </select>
                        </div>
                        <div class="col-sm-12 mt-1">
                            <div class='alert border'>
                                <label>Select Seat</label>
                                <div class='row'>
                                    @for ($i = 1; $i <= $queue->vehicle->seat->rows; $i++)
                                        @for ($j = 1; $j <= $queue->vehicle->seat->columns; $j++)
                                            @php
                                                $myseat = $queue->vehicle->seat->seat_arrangements
                                                    ->where('row', $i)
                                                    ->where('column', $j)
                                                    ->first();
                                                if ($myseat != null) {
                                                    echo "<div class='col'>
                                                            <div class='form-check'>
                                                                <input name='seats[]' class='form-check-input' type='checkbox' value='" .
                                                        $myseat->id .
                                                        "' id='defaultCheck" .
                                                        $myseat->id .
                                                        "'>
                                                                <label class='form-check-label' for='defaultCheck" .
                                                        $myseat->id .
                                                        "'>" .
                                                        $myseat->name .
                                                        "
                                                                </label>
                                                            </div>
                                                        </div>";
                                                } else {
                                                    echo "<div class='col'></div>";
                                                }
                                            @endphp
                                        @endfor
                                        <div class='col-12'></div>
                                    @endfor
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label>Amount</label>
                            <input type='number' name="amount" class="form-control mb-1" placeholder="amount" required
                                readonly>
                        </div>
                        <div class="col-sm-6">
                            <label>Status</label>
                            <select name="status" class="form-control mb-1">
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
            $('#from').select2({
                width: '100%',
                placeholder: 'Select From',
                dropdownParent: $('#routeModal'),
                allowClear: false,
                ajax: {
                    url: '{{ url('queues/search/places') }}/{{ $queue->route_id }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.place.name,
                                    id: item.place.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            $('#to').select2({
                width: '100%',
                placeholder: 'Select From',
                dropdownParent: $('#routeModal'),
                allowClear: false,
                ajax: {
                    url: '{{ url('queues/search/places') }}/{{ $queue->route_id }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.place.name,
                                    id: item.place.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            $('#from, #to').change(function() {

            });

            var table = $('.passengers').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('queues/datatable/bookings/passengers') }}/{{ $queue->id }}",
                    data: function(d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.status = $('.search-form select[name=status]').val();
                    }
                },

                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: '{{ $queue->vehicle->plate }}_{{ $queue->route->from->name }}-{{ $queue->route->to->name }}_passengers',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: '{{ $queue->vehicle->plate }}_{{ $queue->route->from->name }}-{{ $queue->route->to->name }}_passengers',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: '{{ $queue->vehicle->plate }}_{{ $queue->route->from->name }}-{{ $queue->route->to->name }}_passengers',
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
                    {
                        data: 'name',
                        name: 'name'
                    },
                    {
                        data: 'phone',
                        name: 'phone'
                    },
                    {
                        data: 'from.name',
                        name: 'from.name'
                    },
                    {
                        data: 'to.name',
                        name: 'to.name'
                    },
                    {
                        data: 'amount',
                        name: 'amount'
                    },
                    {
                        data: 'passengers',
                        name: 'passengers'
                    },
                    {
                        data: 'start_time',
                        name: 'start_time',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'stop_time',
                        name: 'stop_time',
                        defaultContent: 'N/A'
                    },
                    {
                        data: null,
                        render: function(data, type, row) {
                            return row.creator.firstname + ' ' + row.creator.lastname;
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'paid',
                        name: 'paid',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Paid</span>';
                                default:
                                    return '<span class="badge bg-secondary">Unpaid</span>';
                            }
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'boarded',
                        name: 'boarded',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Yes</span>';
                                default:
                                    return '<span class="badge bg-secondary">No</span>';
                            }
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Active</span>';
                                default:
                                    return '<span class="badge bg-secondary">Inactive</span>';
                            }
                        },
                        orderable: false,
                        searchable: false
                    },

                    {
                        data: 'created_at',
                        name: 'created_at',
                        orderable: false,
                        searchable: false
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

            $('#search-form input[type=text]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            });

            $('#search-form input[name=from_date], #search-form input[name=to_date]').change(function() {
                table.draw();
            });
            $('#search-form select').change(function() {
                table.draw();
            });
            $('.btn-launch-modal').click(function() {
                $('#from').empty();
                $('#to').empty();
                $('#routeModal .modal-title span').text("New ");
                $('#routeModal input[name=id]').val(0);
                $('#routeModal input[type=checkbox]').prop('checked', false);
                $('#routeModal input[name=name]').val("");
                $('#routeModal input[name=amount]').val("");
                $('#routeModal input[name=phone]').val("");
                var data = {
                    id: "{{ $queue->route->from->id }}",
                    text: "{{ $queue->route->from->name }}"
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#from').append(newOption).trigger('change');
                var data = {
                    id: "{{ $queue->route->to->id }}",
                    text: "{{ $queue->route->to->name }}"
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#to').append(newOption).trigger('change');
                $('#routeModal select[name=status]').val(1);
            });

            $('#routeModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#routeModal .feedback').removeClass('d-none');
                $('#routeModal .feedback').removeClass('alert-danger');
                $('#routeModal .feedback').removeClass('alert-success');
                $('#routeModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#routeModal form').serialize();
                $.ajax({
                    url: '{{ url('queues/passenger/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#routeModal .feedback').addClass('alert-success');
                    $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#routeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#routeModal .feedback').addClass('alert-danger');
                    $('#routeModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.id) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors.id +
                                "<br>");
                        }
                        if (data.errors.name) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .name + "<br>");
                        }
                        if (data.errors.amount) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .amount + "<br>");
                        }
                        if (data.errors.phone) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .phone + "<br>");
                        }
                        if (data.errors.seats) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .seats + "<br>");
                        }
                        if (data.errors.from) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .from + "<br>");
                        }
                        if (data.errors.to) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors.to +
                                "<br>");
                        }
                        if (data.errors.status) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .status + "<br>");
                        }
                    } else if (data.error) {
                        $('#routeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    }else if (data.booked_seats) {

                        var count = 0;
                        var resp = "Seat(s) ";
                        $.each(data.booked_seats, function(index, element) {
                            count++;
                            resp += element.seat.name;
                            if (count < data.booked_seats.length) {
                                resp += ", "
                            }
                        });
                        resp += " already taken. Please substitute with an available seat";
                        $('#routeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + resp
                        );
                    } else {
                        $('#routeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                        );
                    }
                    setTimeout(() => {
                        $('#routeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function() {
                $('#routeModal .modal-title span').text("Edit ");
                $('#from').empty();
                $('#to').empty();
                $('#routeModal input[type=checkbox]').prop('checked', false);
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var phone = row.find('.phone').text();
                var from_id = row.find('.from_id').text();
                var from = row.find('.from').text();
                var to_id = row.find('.to_id').text();
                var to = row.find('.to').text();
                var passengers = row.find('.passengers').text();
                var amount = row.find('.amount').text();
                var status = row.find('.status').text();

                $('#routeModal input[name=id]').val(id);
                $('#routeModal input[name=name]').val(name);
                $('#routeModal input[name=phone]').val(phone);
                $('#routeModal input[name=amount]').val(amount);

                var data = {
                    id: from_id,
                    text: from
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#from').append(newOption).trigger('change');

                var data = {
                    id: to_id,
                    text: to
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#to').append(newOption).trigger('change');
                for (i = 1; i <= passengers; i++) {
                    var seatId = row.find('.id-' + i).text();
                    $('#defaultCheck' + seatId).prop('checked', true);
                }
                $('#routeModal select[name=status]').val(status);
            });
            $('#routeModal').on('shown.bs.modal', function(e) {
                $('#routeModal .btnSave').attr('disabled', 'disabled');
                var id = $('#routeModal form input[name=id]').val();
                if (id <= 0) {
                    $.ajax({
                        url: '{{ url('queues/bookings/booked_seats/' . $queue->id) }}',
                        type: 'GET'
                    }).done(function(data) {
                        if (data.booked_seats) {
                            $.each(data.booked_seats, function(index, element) {
                                $('#defaultCheck'+element.seat_id).attr('disabled', 'disabled');
                            });
                            $('#routeModal .btnSave').removeAttr('disabled');
                        } else {
                            $('#routeModel .feedback').removeClass('.d-none');
                            $('#routeModal .feedback').addClass('alert-danger');
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> <b>Whoops!</b> Something went wrong"
                            );

                        }
                    }).fail(function(response) {
                        let data = response.responseJSON;
                        $('#routeModal .feedback').addClass('alert-danger');
                        $('#routeModal .feedback').html("");
                        if (data.error) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.error);
                        } else {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                            );
                        }
                        setTimeout(() => {
                            $('#routeModal .feedback').addClass('d-none');
                        }, 3000);
                        btn.removeAttr('disabled');
                    });
                }
            });
            $('#routeModal form input[type=checkbox]').change(function() {
                var seats = 0;
                $('#routeModal form input[type=checkbox]').each(function() {
                    if (this.checked) {
                        seats++;
                    }
                });
                var amount = "{{ $queue->amount }}";
                amount = amount * seats;
                $('#routeModal form input[name=amount]').val(amount);
            });
        });
    </script>
@endpush
