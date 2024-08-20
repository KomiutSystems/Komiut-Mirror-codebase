@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-coins'></i> <b>All</b> Transactions</h5>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item active">Transactions</li>
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

                <div class='col-sm-4 p-2'>
                    <div class="card bg-white shadow-lg h-100">
                        <div class='card-body'>
                            <table class='w-100'>
                                <tr>
                                    <td class='ps-3'>
                                        TOTALS <span class='text-primary'>(KES)</span><br>
                                        <span class='big totals'></span><br>
                                        <!--<span class='badge border text-primary pr-3 pl-3 border-primary'>KES</span>-->
                                    </td>
                                    <td class='d-flex justify-content-end align-items-center'>
                                        <div
                                            class='myCircle bg-primary d-flex align-items-center justify-content-center text-dark'>
                                            <i class='fas fa-wallet'></i>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class='col-sm-4 p-2'>
                    <div class="card bg-white shadow-lg h-100">
                        <div class='card-body'>
                            <table class='w-100'>
                                <tr>
                                    <td class='ps-3'>
                                        MPESA <span class='text-primary'>(KES)</span><br>
                                        <span class='big mpesa'></span><br>
                                        <!--<span class='badge border text-primary pr-3 pl-3 border-primary'>KES</span>-->
                                    </td>
                                    <td class='d-flex justify-content-end align-items-center'>
                                        <div
                                            class='myCircle bg-primary d-flex align-items-center justify-content-center text-dark'>
                                            <i class='fas fa-mobile'></i>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class='col-sm-4 p-2'>
                    <div class="card bg-white shadow-lg h-100">
                        <div class='card-body'>
                            <table class='w-100'>
                                <tr>
                                    <td class='ps-3'>
                                        CASH <span class='text-primary'>(KES)</span><br>
                                        <span class='big cash'></span><br>
                                        <!--<span class='badge border text-primary pr-3 pl-3 border-primary'>KES</span>-->
                                    </td>
                                    <td class='d-flex justify-content-end align-items-center'>
                                        <div
                                            class='myCircle bg-primary d-flex align-items-center justify-content-center text-dark'>
                                            <i class='fas fa-coins'></i>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-12 mb-3 mt-3">


                    <!-- small box -->
                    <div class="card">
                        <div class="card-header">
                            <form class='search-form row' id='search-form'>
                                <div class="col-sm-3">
                                    <label>Search Name</label>
                                    <input type="text" class="form-control mb-1" name="search" placeholder="Search">
                                </div>
                                <div class="col-sm-3">
                                    <label>Sacco</label>
                                    <select id='sacco' class="form-control mb-1" name="sacco">
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
                            </form>
                        </div>

                        <div class="card-body">
                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Trans ID</th>
                                            <th>Vehicle</th>
                                            <th>Amount</th>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Sacco</th>
                                            <th>Date</th>
                                            <!--<th class='text-end notexport'>Action</th>-->
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

    <!-- Claims Modal -->
    <div class="modal fade" id="vehicleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-user'></i> <span>New </span>
                        Passenger Claim</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('vehicle/direct_line_claims/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <input type='hidden' name='transaction_id' value='0'>
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
@endsection
@push('js')
    <script>
        $(document).ready(function() {
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
            $('#sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#saccoModal'),
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
                $('#sacco').append(newOption).trigger('change');
            }
            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                language: {
                    emptyTable: "No transactions available",
                },
                ajax: {
                    url: "{{ url('dashboard/transactions/datatable/all') }}",
                    data: function(d) {
                        d.search = $('input[name=search]').val();
                        d.from_date = $('input[name=from_date]').val();
                        d.to_date = $('input[name=to_date]').val();
                        d.sacco = $('select[name=sacco]').val();
                    }
                },
                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'all_transactions_' + $("#from_date").val() + '-' + $("#to_date").val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'all_transactions_' + $("#from_date").val() + '-' + $("#to_date").val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'all_transactions_' + $("#from_date").val() + '-' + $("#to_date").val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                dom: "<'top'B>rt<'bottom'lip><'clear'>", //'lBtrip',
                "lengthMenu": [
                    [20, 100, 250, 500, 1000],
                    [20, 100, 250, 500, 1000]
                ],
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'transid',
                        name: 'transid',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'vehicle.plate',
                        name: 'vehicle.plate',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'amount',
                        name: 'amount'
                    },
                    {
                        data: 'name',
                        name: 'name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'phone',
                        name: 'phone',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'vehicle.sacco.name',
                        name: 'vehicle.sacco.name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'transdate',
                        name: 'transdate'
                    },/*
                    {
                        data: 'action',
                        name: 'action'
                    },*/
                ]
            });
            var timer = null;
            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                    getCardsData();
                }, 1000);
            })
            $('#sacco, #from_date, #to_date').change(function() {
                table.draw();
                getCardsData();
            });
            $('#search-form').on('submit', function(e) {
                e.preventDefault();
                table.draw();
                getCardsData();
            });
            getCardsData();
            function getCardsData() {
                let from_date = $('#from_date').val();
                let to_date = $('#to_date').val();
                let sacco = $('#sacco').val();
                let search = $('input[name=search').val();

                $('.totals').html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
                $('.cash').html('<i class="fas fa-spinner fa-pulse"></i> Loading..');
                $('.mpesa').html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
                $.ajax({
                    url: "{{ url('dashboard/transactions/cards') }}",
                    type: "GET",
                    data: {
                        "search":search,
                        "from_date": from_date,
                        "to_date": to_date,
                        "sacco":sacco
                    }
                }).done(function(data) {
                    //cards
                    if (data.cash) {
                        $('.totals').html(data.totals);
                        $('.cash').html(data.cash);
                        $('.mpesa').html(data.mpesa);
                    }

                }).fail(function() {
                    $('.totals').html("-");
                    $('.cash').html("-");
                    $('.mpesa').html("-");
                });
            }

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
                    url: '{{ url('dashboard/vehicles/direct_line_claims/add') }}',
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
                var transaction_id = row.find('.transaction_id').text();
                var vehicle = row.find('.vehicle').text();
                var vehicle_id = row.find('.vehicle_id').text();
                var name = row.find('.name').text();
                var phone = row.find('.phone').text();
                var travel_date = row.find('.travel_date').text();
                var status = row.find('.status').text();

                $('#vehicleModal input[name=id]').val(id);
                $('#vehicleModal input[name=transaction_id]').val(transaction_id);
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
        });
    </script>
@endpush
