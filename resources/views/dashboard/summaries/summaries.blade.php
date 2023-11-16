@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-chart-line'></i> Summaries</h5>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item active">Summaries</li>
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
                                        placeholder='To Date' value='{{ Carbon\Carbon::today()->format('Y-m-d') }}'>
                                </div>
                            </form>
                        </div>

                        <div class="card-body">
                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Vehicle</th>
                                            <th>Sacco</th>
                                            <th>Mpesa</th>
                                            <th>Mpesa Txn</th>
                                            <th>Cash</th>
                                            <th>Cash Txn</th>
                                            <th>Totals</th>
                                            <th>Total Txn</th>
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
@endsection
@push('js')
    <script>
        $(document).ready(function() {
            flatpickr("#from_date, #to_date", {
                altInput: true,
                altFormat: "F j, Y",
                enableTime: false,
                dateFormat: "Y-m-d",
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
                    emptyTable: "No Summaries available",
                },
                ajax: {
                    url: "{{ url('datatable/summaries') }}",
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
                        data: 'mpesa_amount',
                        name: 'mpesa_amount'
                    },
                    {
                        data: 'mpesa_txn',
                        name: 'mpesa_txn',
                    },
                    {
                        data: 'cash_amount',
                        name: 'cash_amount',
                    },
                    {
                        data: 'cash_txn',
                        name: 'cash_txn',
                    },
                    {
                        data: 'totals',
                        name: 'totals',
                        searchable: false,
                        orderable: false,
                    },
                    {
                        data: 'total_txn',
                        name: 'total_txn',
                        searchable: false,
                        orderable: false,
                    },
                ]
            });
            var timer = null;
            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            })
            $('#sacco, #from_date, #to_date').change(function() {
                table.draw();
            });
            $('#search-form').on('submit', function(e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function() {
                $('#saccoModal .modal-title span').text("New ");
                $('#saccoModal input[name=id]').val(0);
                $('#saccoModal input[name=name]').val("");
                $('#saccoModal input[name=slogan]').val("");
                $('#saccoModal input[name=phone]').val("");
                $('#saccoModal input[name=status]').val("");
            });
            $('#saccoModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#saccoModal .feedback').removeClass('d-none');
                $('#saccoModal .feedback').removeClass('alert-danger');
                $('#saccoModal .feedback').removeClass('alert-success');
                $('#saccoModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#saccoModal form').serialize();
                $.ajax({
                    url: '{{ url('sacco/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#saccoModal .feedback').addClass('alert-success');
                    $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#saccoModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#saccoModal .feedback').addClass('alert-danger');
                    $('#saccoModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#saccoModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .name + "<br>");
                        }
                    } else if (data.error) {
                        $('#saccoModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#saccoModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                        );
                    }
                    setTimeout(() => {
                        $('#saccoModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });
            $(document).on('click', '.table .btn-edit', function() {
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
        });
    </script>
@endpush
