@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-chart-line'></i> Summaries</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @if (auth()->user()->can('Add Summaries') || auth()->user()->can('Edit Summaries'))
                        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i
                                class='fas fa-save'></i> Update</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Summaries</li>
                        </ol>
                    @endif
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
                @can('View Transaction Cards')
                <input type='hidden' name='show' value="yes"/>
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
                @endcan

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
                                            <th>Expense/Fee</th>
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
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-save'></i> <span>Update </span>
                        Summaries</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('dashboard/summaries/update') }}" class="row">
                        @csrf
                        <div class='col-sm-12 form-group'>
                            <label>Date to update</label>
                            <input type='date' id='date' class='form-control' name='date' placeholder="Date"
                                autocomplete="off"/>
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
            $('#date').flatpickr({
                static: true,
                altInput: true,
                altFormat: "F j, Y",
                enableTime: false,
                dateFormat: "Y-m-d",
            });
            flatpickr("#from_date, #to_date", {
                altInput: true,
                altFormat: "F j, Y",
                enableTime: false,
                dateFormat: "Y-m-d",
                //defaultDate: new Date(),
            });
            $('.flatpickr-wrapper').addClass('w-100');
            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
            var show = $('input[name=show]').val();
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
                    url: "{{ url('dashboard/datatable/summaries') }}",
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
                    {
                        data: 'expense_fee_amount',
                        name: 'expense_fee_amount',
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
                    url: "{{ url('dashboard/summaries/cards') }}",
                    type: "GET",
                    data: {
                        "search": search,
                        "from_date": from_date,
                        "to_date": to_date,
                        "sacco": sacco
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
                    url: '{{ url('dashboard/summaries/update') }}',
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
                        if (data.errors.date) {
                            $('#userModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .date + "<br>");
                        }

                    } else if (data.error) {
                        $('#userModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#userModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                        );
                    }
                    setTimeout(() => {
                        $('#userModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });
        });
    </script>
@endpush
