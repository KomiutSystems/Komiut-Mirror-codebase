@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-credit-card'></i> Expense & Fees</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Payment Settings')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#userModal"><i
                                class='fas fa-plus'></i> Add</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Expense & Fees Settings</li>
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
                            <form id='search-form' class='row mb-2'>
                                <div class='col-sm-3'>
                                    <label>Search Name</label>
                                    <input name='search' class='form-control' placeholder="Search" />
                                </div>
                                <div class='col-sm-3'>
                                    <label>Search Sacco</label>
                                    <select name='sacco' id='search-sacco' class='form-control'></select>
                                </div>
                                <div class='col-sm-3'>
                                    <label>Search Expense/Fee</label>
                                    <select name='expense_fee' id='search-expense-fees' class='form-control'></select>
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
                        <div class='card-body'>
                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Vehicle</th>
                                            <th>Sacco</th>
                                            <th>Expense/Fee</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Type</th>
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
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span> Expense &
                        Fees
                    </h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('dashboard/expense_and_fees/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class='col-sm-12 form-group'>
                            <label>Amount</label>
                            <input type='number' min='1' class='form-control' name='amount' placeholder="Amount"
                                autocomplete="off" />
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Expense/Fee</label>
                            <select name="expense_fee" class='form-control' id='expense_fees'>
                            </select>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Vehicle</label>
                            <select name="vehicle" class='form-control' id='vehicles'>
                            </select>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Transaction Date</label>
                            <input type='text' id='transaction_date' name='trans_date' class='form-control'
                                placeholder="Transaction Date" />
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Status</label>
                            <select name='status' class='form-control' id='status'>
                                <option value="1">Active</option>
                                <option value="0">In-Active</option>
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
        $(document).ready(function() {
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            flatpickr("#transaction_date", {
                enableTime: false,
                dateFormat: "Y-m-d",
                //defaultDate: new Date(),
            });

            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
            $('#expense_fees').select2({
                width: '100%',
                placeholder: 'Select Expense/Fees',
                dropdownParent: $('#userModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('dashboard/expense_and_fees/search') }}',
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
            $('#vehicles').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                dropdownParent: $('#userModal'),
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
            $('#type, #status').select2({
                width: '100%',
                placeholder: 'Select',
                dropdownParent: $('#userModal'),
                allowClear: false
            });


            $('#search-sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#userModal'),
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

            $('#search-expense-fees').select2({
                width: '100%',
                placeholder: 'Select Expense/Fee',
                allowClear: true,
                ajax: {
                    url: '{{ url('dashboard/expense_and_fees/search') }}',
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
                processing: true,
                serverSide: true,
                language: {
                    emptyTable: "No Expense and Fees available",
                },
                ajax: {
                    url: "{{ url('dashboard/datatable/expense_and_fees') }}",
                    data: function(d) {
                        d.search = $('#search-form input[name=search]').val();
                        d.expense_fee = $('#search-form select[name=expense_fee]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                        d.from_date = $('#search-form input[name=from_date]').val();
                        d.to_date = $('#search-form input[name=to_date]').val();
                    }
                },
                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'Vehicle Expense/Fees '+$('#from_date').val()+"_"+$('#to_date').val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'Vehicle Expense/Fees '+$('#from_date').val()+"_"+$('#to_date').val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'Vehicle Expense/Fees '+$('#from_date').val()+"_"+$('#to_date').val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [
                    [20, 100, 250, 500, 1000],
                    [20, 100, 250, 500, 1000]
                ],
                dom: "<'top'B>rt<'bottom'lip><'clear'>", //'lBtrip', //'lfBtrip'
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'vehicle.plate',
                        name: 'vehicle.plate',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'vehicle.sacco.name',
                        name: 'vehicle.sacco.name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'expense_fee.name',
                        name: 'expense_fee.name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'amount',
                        name: 'amount',
                        defaultContent: 'N/A'
                    },  {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Active</span>';
                                default:
                                    return '<span class="badge bg-secondary">Inactive</button>';
                            }
                        }
                    },
                    {
                        data: 'expense_fee.type',
                        name: 'expense_fee.type',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'trans_date',
                        name: 'trans_date'
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
            $('#from_date,#to_date, #search-form select').change(function() {
                table.draw();
            });

            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000)
            });
            $('.btn-launch-modal').click(function() {
                $('#userModal .modal-title span').text("New ");
                $('#userModal input[name=id]').val(0);
                $('#vehicles').empty();
                $('#expense_fees').empty();
                $('#userModal input[name=amount]').val("");
                $('#userModal input[name=trans_date]').val("");
                $('#userModal select[name=status]').val(1);
            });
            $('#userModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#userModal .feedback').removeClass('d-none');
                $('#userModal .feedback').removeClass('alert-danger');
                $('#userModal .feedback').removeClass('alert-success');
                $('#userModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#userModal form').serialize();
                $.ajax({
                    url: '{{ url('dashboard/expense_and_fees/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#userModal .feedback').addClass('alert-success');
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#userModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#userModal .feedback').addClass('alert-danger');
                    $('#userModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.amount) {
                            $('#userModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .amount + "<br>");
                        }
                        if (data.errors.vehicle) {
                            $('#userModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .vehicle + "<br>");
                        }

                        if (data.errors.expense_fee) {
                            $('#userModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .expense_fee + "<br>");
                        }

                        if (data.errors.trans_date) {
                            $('#userModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .expense_fee + "<br>");
                        }

                        if (data.errors.status) {
                            $('#userModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .status + "<br>");
                        }

                    } else if (data.error) {
                        $('#userModal .feedback').append(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#userModal .feedback').append(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                        );
                    }
                    setTimeout(() => {
                        $('#userModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });
            $(document).on('click', '.table .btn-edit', function() {
                $('#userModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var vehicle = row.find('.vehicle').text();
                var vehicle_id = row.find('.vehicle_id').text();
                var expense_fee = row.find('.expense_fee').text();
                var expense_fee_id = row.find('.expense_fee_id').text();
                var amount = row.find('.amount').text();
                var trans_date = row.find('.trans_date').text();
                var status = row.find('.status').text();

                $('#userModal input[name=id]').val(id);
                if (expense_fee_id > 0) {
                    var data = {
                        id: expense_fee_id,
                        text: expense_fee
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#expense_fees').append(newOption).trigger('change');
                }
                if (vehicle_id > 0) {
                    var data = {
                        id: vehicle_id,
                        text: vehicle
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#vehicles').append(newOption).trigger('change');
                }
                $('#userModal input[name=amount]').val(amount);
                $('#userModal select[name=status]').val(status);
                $('#userModal input[name=trans_date]').val(trans_date);
            });
        });
    </script>
@endpush
