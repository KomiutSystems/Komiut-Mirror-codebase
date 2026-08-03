@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 d-flex align-items-center">
                <div class="col-sm-6">
                    <h5 style='font-weight: 300'><i class='fas fa-tachometer-alt'></i> Dashboard</h5>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <div class='row'>
                        <div class='col'>
                            <select name="duration" class="form-control mb-1" id='duration'>

                                <option value="0">This Week</option>
                                <option value="1">This Month</option>
                                <option value="2">Last 3 months</option>
                                <option value="3">Last 6 months</option>
                                <option value="4">Last 1 Year</option>
                            </select>
                        </div>
                        <div class='col'>
                            <select name="sacco" class="form-control mb-1" id='sacco'>
                            </select>
                        </div>
                    </div>
                    <!--
                                <ol class="breadcrumb float-sm-right">
                                    <li class="breadcrumb-item"><a href="{{ url('home') }}">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Home</li>
                                </ol>-->
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

                <div class='col-sm-12 mt-3'>
                    <div class='row'>
                        <div class="col-md-8">
                            <!-- small box -->
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class='fas fa-chart-bar'></i>&nbsp;<span style='font-weight:300;'>Collections</span>
                                        Overview</h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart">
                                        <canvas id="myChart"
                                            style="min-height: 250px; height: 250px; max-height: 250px; max-width: 100%;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <!-- small box -->
                            <div class="card">
                                <div class="card-header"><h5><i class='fas fa-chart-pie'></i>&nbsp;<span style='font-weight:300;'>Collections</span>
                                    Summary</h5></div>
                                    <div class="card-body">
                                    <canvas id="myPieChart"
                                        style="min-height: 250px; height: 250px; max-height: 250px; max-width: 100%;"></canvas>
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
@endsection
@push('js')
    <script>
        $(document).ready(function() {

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
            $('#duration').select2();

            var ctx = document.getElementById('myChart').getContext('2d');
            var ctx1 = document.getElementById('myPieChart');
            var myChart;
            var pieChart;
            var year = 0;
            var mydata = [12, 19, 3, 5, 2, 3, 23, 14];
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var mymonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            getDashboardData();
            $('#duration, #sacco').change(function() {
                getDashboardData();
            });

            function getDashboardData() {
                year = $('#duration').val();
                var sacco = $('#sacco').val();

                $('.totals').html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
                $('.cash').html('<i class="fas fa-spinner fa-pulse"></i> Loading..');
                $('.mpesa').html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
                $.ajax({
                    url: "{{ url('dashboard/home/dashboard') }}",
                    type: "GET",
                    data: {
                        "year": year,
                        "sacco": sacco
                    }
                }).done(function(data) {
                    //cards
                    if (data.cash) {
                        $('.totals').html(data.totals);
                        $('.cash').html(data.cash);
                        $('.mpesa').html(data.mpesa);
                    }
                    //graphs
                    var response = JSON.parse(data.transactions);
                    var xaxis = JSON.parse(data.xaxis);
                    mymonths = [];
                    $.each(xaxis, function(index, value) {
                        mymonths.push(value);
                    });
                    var myData = new Array();
                    if (response.length > 0) {
                        if (year > 1) {
                            //var month = response[response.length-1].month;
                            var size = xaxis.length;
                            for (var i = 0; i < size; i++) {
                                var totals = 0;
                                $.each(response, function(index, value) {
                                    if (mymonths[i] == months[value.month - 1]) {
                                        totals = value.totals;
                                    }
                                });
                                myData.push(totals);
                            }
                        } else {
                            //var day = response[response.length-1].day;
                            for (var i = 0; i <= mymonths.length; i++) {
                                var totals = 0;
                                $.each(response, function(index, value) {
                                    if (mymonths[i] == value.day) {
                                        totals = value.totals;
                                    }
                                });
                                myData.push(totals);
                            }
                        }
                    }
                    var pieData = [data.mpesas, data.cashes];
                    getLineChart(myData);
                    getPieChart(pieData);

                }).fail(function() {
                    $('.totals').html("-");
                    $('.cash').html("-");
                    $('.mpesa').html("-");
                });
            }
            //getLineChart(mydata);
            //getPieChart(mydata);

            //getLineChart(mydata);
            function getLineChart(mydata) {
                if (myChart != null) {
                    myChart.destroy();
                }
                myChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: mymonths,
                        datasets: [{
                            label: '',
                            data: mydata,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0)'
                            ],
                            borderColor: [
                            '#4D2DB7'
                            ],
                            borderWidth: 3
                        }]
                    },
                    options: {
                        fontFamily: "Poppins",
                        legend: {
                            display: false
                        },
                        scales: {
                            xAxes: [{
                                gridLines: {drawOnChartArea: false
                                    //display: false
                                },
                                scaleLabel: {
                                    display: true,
                                    labelString: year == 0 ? 'Days' : 'Month',
                                    fontColor: '#000',
                                    fontFamily: "Poppins",
                                },
                                ticks: {
                                    beginAtZero: false,
                                    fontFamily: "Poppins",
                                    fontColor: "#000",
                                }
                            }],
                            yAxes: [{
                                gridLines: {drawOnChartArea: false
                                    //display: false
                                },
                                scaleLabel: {
                                    display: true,
                                    labelString: 'Amount (KES)',
                                    fontColor: '#000',
                                    fontFamily: "Poppins",
                                },
                                ticks: {
                                    beginAtZero: false,
                                    fontFamily: "Poppins",
                                    fontColor: "#000",
                                }
                            }]
                        }
                    }
                });
            }

            //getPieChart
            function getPieChart(mydata) {
                if (pieChart != null) {
                    pieChart.destroy();
                }
                pieChart = new Chart(ctx1, {
                    type: 'pie',
                    data: {
                        labels: ['mpesa', 'cash'],
                        datasets: [{
                            label: '',
                            data: mydata,
                            backgroundColor: [
                                '#DA4453',
                                '#89216B'
                            ],
                            borderColor: [
                                '#DA4453',
                                '#89216B'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        fontFamily: "Source Sans Pro",
                        legend: {
                            display: false
                        },
                        responsive: true
                    }
                });
            }
        });
    </script>
@endpush
