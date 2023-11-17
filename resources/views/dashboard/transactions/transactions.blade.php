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
                    url: "{{ url('transactions/datatable/all') }}",
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
                    url: "{{ url('transactions/cards') }}",
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
        });
    </script>
@endpush
