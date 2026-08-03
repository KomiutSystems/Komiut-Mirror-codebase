@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-car-crash'></i> <b>Direct Line</b> Claims</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Vehicles')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#vehicleModal"><i
                                class='fas fa-plus'></i> Add Claim
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Direct Line Claims</li>
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
                    <div class="card">
                        <div class="card-header">
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
                                        placeholder='To Date' value='{{ Carbon\Carbon::today()->format('Y-m-d') }} 23:59'>
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
                        <div class='card-body'>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Vehicle</th>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Travel Date</th>
                                            <!--<th>Source</th>-->
                                            <th>Sacco</th>
                                            <th>Status</th>
                                            <!--<th>Response</th>-->
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
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-user'></i> <span>New </span>
                        Passenger Claim</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('vehicle/user/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class="col-sm-12 form-group">
                            <label>Vehicle</label>
                            <select name="vehicle" class="form-control mb-1" id='vehicle'>
                            </select>
                        </div>
                        <div class="col-sm-12 form-group">
                            <label>Passenger Name</label>
                            <input type='text' name='name' class='form-control' placeholder='Passenger Name' />
                        </div>
                        <div class="col-sm-12 form-group">
                            <label>Passenger Phone</label>
                            <input type='text' name='phone' class='form-control' placeholder='Passenger Phone' />
                        </div>
                        <div class="col-sm-6 form-group">
                            <label>Travel Date</label>
                            <input type='text' name='travel_date' id='travel_date' class='form-control'
                                placeholder='Travel Date' />
                        </div>
                        <div class='col-sm-6 form-group'>
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

    <!-- Profile Modal -->
    <div class="modal fade" id="viewClaimModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-eye'></i> <span>View </span>
                        Claim</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <table class='w-100 table-striped'>
                        <tr>
                            <td class='p-2'><b>Vehicle:</b></td>
                            <td class='p-2 vehicle-span'></td>
                        </tr>
                        <tr>
                            <td class='p-2'><b>Name:</b></td>
                            <td class='p-2 name-span'></td>
                        </tr>
                        <tr>
                            <td class='p-2'><b>Phone:</b></td>
                            <td class='p-2 phone-span'></td>
                        </tr>
                        <tr>
                            <td class='p-2'><b>Travel Date:</b></td>
                            <td class='p-2 travel-date-span'></td>
                        </tr>
                        <tr>
                            <td class='p-2'><b>Sacco:</b></td>
                            <td class='p-2 sacco-span'></td>
                        </tr>
                        <tr>
                            <td class='p-2'><b>Source:</b></td>
                            <td class='p-2 source-span'></td>
                        </tr>
                        <tr>
                            <td class='p-2'><b>Claim Response:</b></td>
                            <td class='p-2 claim-response-span'></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><i
                            class='fas fa-times'></i> Close
                    </button>
                    <!--
                        <button type="button" class="btn btn-primary btn-sm btnSave"><i class='fas fa-paper-plane'></i> Save
                            changes
                        </button>-->
                </div>
            </div>
        </div>
    </div>
@endsection
@push('js')
    <script>
        $(document).ready(function() {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            flatpickr("#from_date, #to_date, #travel_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
            $('#user').select2({
                width: '100%',
                placeholder: 'Select User',
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
                allowClear: sacco_id > 0 ? false : true,
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
            if (sacco_id > 0) {
                var data = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#search-sacco').append(newOption).trigger('change');
            }


            var table = $('.table').DataTable({
                scrollX: true,
                fixedColumns: {
                    //left: 2,
                    right: 1,
                    left: 0
                },
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('vehicles/datatable/direct_line_claims') }}",
                    data: function(d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('#search-form input[name=from_date]').val();
                        d.to_date = $('#search-form input[name=to_date]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                    }
                },
                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'Vehicle Users',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'Vehicle Users',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'Vehicle Users',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [
                    [20, 100, 250, 500, 1000],
                    [20, 100, 250, 500, 1000]
                ],
                dom: "<'top'B>rt<'bottom'lip><'clear'>", //'lBtrip',
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'vehicle.plate',
                        name: 'vehicle.plate',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'passenger_name',
                        name: 'passenger_name',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'passenger_phone',
                        name: 'passenger_phone',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'travel_date',
                        name: 'travel_date',
                        searchable: false,
                        orderable: false
                    },
                    /*
                                        {
                                            data: 'source',
                                            name: 'source',
                                            searchable: false,
                                            orderable: false
                                        },*/
                    {
                        data: 'vehicle.sacco.name',
                        name: 'vehicle.sacco.name',
                        defaultContent: 'N/A',
                        searchable: false,
                        orderable: false
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
                        },
                        searchable: false,
                        orderable: false
                    },
                    /*{
                        data: 'claim_response',
                        name: 'claim_response'
                    },*/
                    {
                        data: 'created_at',
                        name: 'created_at',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false
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
                $('#vehicle').empty();
                //$('#vehicleModal input[name=status]').val(1);
                $('#vehicleModal input[name=phone]').val("");
                $('#vehicleModal input[name=name]').val("");
                $('#vehicleModal input[name=travel_date]').val("");
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
                    url: '{{ url('vehicles/direct_line_claims/add') }}',
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
                            $('#vehicleModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .vehicle + "<br>");
                        }
                        if (data.errors.phone) {
                            $('#vehicleModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .phone + "<br>");
                        }
                        if (data.errors.name) {
                            $('#vehicleModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .name + "<br>");
                        }
                        if (data.errors.travel_date) {
                            $('#vehicleModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .travel_date + "<br>");
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
                var name = row.find('.name').text();
                var phone = row.find('.phone').text();
                var travel_date = row.find('.travel_date').text();
                var status = row.find('.status').text();

                $('#vehicleModal input[name=id]').val(id);
                $('#vehicleModal select[name=status]').val(status);
                if (vehicle_id > 0) {
                    var data = {
                        id: vehicle_id,
                        text: vehicle
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#vehicle').append(newOption).trigger('change');
                }
                $('#vehicleModal input[name=phone]').val(phone);
                $('#vehicleModal input[name=name]').val(name);
                $('#vehicleModal input[name=travel_date]').val(travel_date);
            });

            $(document).on('click', '.table .btn-view', function() {
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var vehicle = row.find('.vehicle').text();
                var name = row.find('.name').text();
                var phone = row.find('.phone').text();
                var travel_date = row.find('.travel_date_1').text();
                var sacco = row.find('.sacco').text();
                var source = row.find('.source').text();
                var claim_response = row.find('.claim_response').text();
                var status = row.find('.status').text();

                $('#viewClaimModal .vehicle-span').text(vehicle);
                $('#viewClaimModal .name-span').text(name);
                $('#viewClaimModal .phone-span').text(phone);
                $('#viewClaimModal .travel-date-span').text(travel_date);
                $('#viewClaimModal .sacco-span').text(sacco);
                $('#viewClaimModal .source-span').text(source);
                $('#viewClaimModal .claim-response-span').text(claim_response);
            });

            $(document).on('click', '.table .btn-delete', function() {

                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var vehicle = row.find('.vehicle').text();
                var user = row.find('.user').text();
                var btn = $(this);
                const swalWithBootstrapButtons = Swal.mixin({
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-danger mr-1"
                    },
                    buttonsStyling: false
                });
                swalWithBootstrapButtons.fire({
                    title: "Are you sure?",
                    html: "Are you sure you want to delink <b>\'" + vehicle +
                        "\'</b> from user <b>\'" + user +
                        "\'</b>? You won't be able to revert this!",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "Yes, delete it!",
                    cancelButtonText: "No, cancel!",
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        btn.attr('disabled', 'disabled');
                        btn.html("<i class='fas fa-spinner fa-pulse'></i> Loading...");
                        $.ajax({
                            url: '{{ url('vehicles/user/remove') }}',
                            type: 'POST',
                            data: {
                                'id': id
                            },
                        }).done(function(data) {
                            swalWithBootstrapButtons.fire({
                                title: "Success",
                                text: data.success,
                                icon: "success"
                            });
                            table.draw();
                        }).fail(function(response) {
                            let data = response.responseJSON;
                            console.log(data);
                            if (data.errors) {
                                if (data.errors.id) {
                                    swalWithBootstrapButtons.fire({
                                        title: "Error",
                                        text: data.errors.id,
                                        icon: "error"
                                    });
                                }

                            } else if (data.error) {
                                swalWithBootstrapButtons.fire({
                                    title: "Error",
                                    text: data.error,
                                    icon: "error"
                                });
                            } else {
                                swalWithBootstrapButtons.fire({
                                    title: "Whoops!",
                                    text: "Something went wrong with the server!",
                                    icon: "error"
                                });
                            }
                            btn.html("<i class='fas fa-trash'></i> Delete");
                            btn.removeAttr('disabled');
                        });
                    }
                    /*else if (
                                           result.dismiss === Swal.DismissReason.cancel
                                       ) {
                                           swalWithBootstrapButtons.fire({
                                               title: "Cancelled",
                                               text: "Your imaginary file is safe :)",
                                               icon: "error"
                                           });
                                       }*/

                });
            });

        });
    </script>
@endpush
