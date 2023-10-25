@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                <h1 class="m-0"><i class='fas fa-tachometer-alt'></i> Dashboard</h1>
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
            <div class='col-sm-4'>
                <div class="card">
                    <div class='card-body'>
                        <h5><i class='fas fa-wallet text-info'></i> TOTALS (KES)</h5>
                        <span class='big totals'></span>
                    </div>
                </div>
            </div>

            
            <div class='col-sm-4'>
                <div class="card">
                    <div class='card-body'>
                        <h5><i class='fas fa-mobile text-success'></i> MPESA (KES)</h5>
                        <span class='big mpesa'></span>
                    </div>
                </div>
            </div>
            
            <div class='col-sm-4'>
                <div class="card">
                    <div class='card-body'>
                        <h5><i class='fas fa-coins text-primary'></i> CASH (KES)</h5>
                        <span class='big cash'></span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8 mb-3">
                <!-- small box -->
                <div class="card card-primary card-outline">
                    <div class="card-body box-profile">
                        <div class="chart">
                            <canvas id="myChart" style="min-height: 250px; height: 250px; max-height: 250px; max-width: 100%;"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-3">
                <!-- small box -->
                <div class="card card-primary card-outline">
                    <div class="card-body box-profile">
                        <canvas id="myPieChart" style="min-height: 250px; height: 250px; max-height: 250px; max-width: 100%;"></canvas>
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
    $(document).ready(function () {
        
        var sacco_id = "{{ $sacco != null?$sacco->id:0 }}";
        var sacco = "{{ $sacco != null?$sacco->name:0 }}";
        $('#sacco').select2({
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
            $('#sacco').append(newOption).trigger('change');
        }
        $('#duration').select2();

        var ctx = document.getElementById('myChart').getContext('2d');
        var ctx1 = document.getElementById('myPieChart');
        var myChart;
        var pieChart;
        var year = 0;
        var mydata = [12, 19, 3, 5, 2, 3, 23,14];
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var mymonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        getDashboardData();
        $('#duration, #sacco').change(function(){
            getDashboardData();
        });
        function getDashboardData(){
            year = $('#duration').val();
            var sacco = $('#sacco').val();

            $('.totals').html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
            $('.cash').html('<i class="fas fa-spinner fa-pulse"></i> Loading..');
            $('.mpesa').html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
            $.ajax({
                url: "{{url('home/dashboard')}}",
                type: "GET",
                data: {"year":year,"sacco":sacco}
            }).done(function (data) {
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
                $.each(xaxis, function(index, value){
                    mymonths.push(value);
                });
                var myData = new Array();
                if(response.length > 0){
                    if(year > 1){
                        //var month = response[response.length-1].month;
                        var size = xaxis.length;
                        for(var i=0; i < size; i++){
                            var totals = 0;
                            $.each(response, function(index, value){
                                if(mymonths[i]==months[value.month-1]){
                                    totals = value.totals;
                                }
                            });
                            myData.push(totals);
                        }
                    }else{
                        //var day = response[response.length-1].day;
                        for(var i=0; i <= mymonths.length; i++){
                            var totals = 0;
                            $.each(response, function(index, value){
                                if(mymonths[i]==value.day){
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

            }).fail(function () {
                $('.totals').html("-");
                $('.cash').html("-");
                $('.mpesa').html("-");
            });
        }
        //getLineChart(mydata);
        //getPieChart(mydata);

        //getLineChart(mydata);
        function getLineChart(mydata){
            if(myChart != null){
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
                            'indigo'
                        ],
                        borderWidth: 3
                    }]
                },
                options: {
                    fontFamily: "Open Sans",
                    legend: {
                        display: false
                    },
                    scales: {
                        xAxes: [{
                            gridLines: {
                                display:false
                            },
                            scaleLabel: {
                                display: true,
                                labelString: year==0?'Days':'Month',
                                fontColor: '#000',
                                fontFamily: "Source Sans Pro",
                            },
                            ticks: {
                                beginAtZero: false,
                                fontFamily: "Source Sans Pro",
                                fontColor: "#000",
                            }
                        }],
                        yAxes: [{
                            gridLines: {
                                display:false
                            },
                            scaleLabel: {
                                display: true,
                                labelString: 'Amount (KES)',
                                fontColor: '#000',
                                fontFamily: "Source Sans Pro",
                            },
                            ticks: {
                                beginAtZero: false,
                                fontFamily: "Source Sans Pro",
                                fontColor: "#000",
                            }
                        }]
                    }
                }
            });
        }

        //getPieChart
        function getPieChart(mydata){
            if(pieChart != null){
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
        var table = $('.table').DataTable({
            processing: true,
            serverSide: true,
            ajax: "{{ url('users/datatable/users') }}",
            dom: 'lBtrip', //'lfBtrip'
            columns: [
                //{ data: 'DT_RowIndex', name: 'DT_RowIndex' },
                { data: 'image', name: 'image' },
                { data: 'name', name: 'name' },
                { data: 'email', name: 'email' },
                { data: 'phone', name: 'phone' },
                { data: 'files', name: 'files' },
                { data: 'country.name', name: 'country.name' },
                { data: 'role', name: 'role' },
                { data: 'status', name: 'status' },
                { data: 'created_at', name: 'created_at' },
                {
                    data: 'action',
                    name: 'action',
                    orderable: true,
                    searchable: true
                },
            ]
        });
        $('#countries').select2({
            width: '100%',
            placeholder: 'Select Country',
            //dropdownParent: $('#modelModal'),
            allowClear: true,
            ajax: {
                url: '{{url("index/search/countries")}}',
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
        $('#roles').select2({
            width: '100%',
            placeholder: 'Select Role',
            //dropdownParent: $('#modelModal'),
            allowClear: true,
            ajax: {
                url: '{{url("users/search/roles")}}',
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
        $('.btn-launch-modal').click(function(){
            $('#userModal .modal-title span').text("New User");
            $('#userModal input[name=id]').val(0);
            $('#userModal input[name=firstname]').val("");
            $('#userModal input[name=lastname]').val("");
            $('#userModal input[name=email').val("");
            $('#userModal input[name=phone]').val("");
            $('#roles').empty();
            $('#countries').empty();
        });
        $('#userModal .btnSave').click(function () {
            var btn = $(this);
            btn.attr('disabled', 'disabled');
            $('#userModal .feedback').removeClass('d-none');
            $('#userModal .feedback').removeClass('alert-danger');
            $('#userModal .feedback').removeClass('alert-success');
            $('#userModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
            var formData = $('#userModal form').serialize();
            $.ajax({
                url: '{{ url("users/add") }}',
                type: 'POST',
                data: formData
            }).done(function (data) {
                $('#userModal .feedback').addClass('alert-success');
                $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                table.draw();
                setTimeout(() => {
                    $('#userModal .feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            }).fail(function (response) {
                let data = response.responseJSON;
                $('#userModal .feedback').addClass('alert-danger');
                $('#userModal .feedback').html("");
                if (data.errors) {
                    if (data.errors.firstname) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.firstname + "<br>");
                    }
                    if (data.errors.lastname) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.lastname + "<br>");
                    }
                    if (data.errors.email) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.email + "<br>");
                    }
                    if (data.errors.phone) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.phone + "<br>");
                    }
                    if (data.errors.country) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.country + "<br>");
                    }
                    if (data.errors.status) {
                        $('#userModal .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                    }
                } else if (data.error) {
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                } else {
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                }
                setTimeout(() => {
                    $('#userModal .feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            });
        });
        $(document).on('click', '.table .btn-edit', function(){
            $('#userModal .modal-title span').text("Edit User");
            $('#roles').empty();
            $('#countries').empty();
            var row = $(this).closest('tr');
            var id = row.find('.id').text();
            var firstname = row.find('.firstname').text();
            var lastname = row.find('.lastname').text();
            var email = row.find('.email').text();
            var phone = row.find('.phone').text();
            var status = row.find('.status').text();
            var roleId = parseInt(row.find('.role_id').text());
            var roleName = row.find('.role_name').text();
            var countryId = parseInt(row.find('.country_id').text());
            var countryName = row.find('.country_name').text();
            if(roleId > 0){
                var data = {
                    id: roleId,
                    text: roleName
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#roles').append(newOption).trigger('change');
            }
            var data = {
                id: countryId,
                text: countryName
            };
            var newOption = new Option(data.text, data.id, false, false);
            $('#countries').append(newOption).trigger('change');

            $('#userModal input[name=id]').val(id);
            $('#userModal input[name=firstname]').val(firstname);
            $('#userModal input[name=lastname]').val(lastname);
            $('#userModal input[name=email').val(email);
            $('#userModal input[name=phone]').val(phone);
            $('#userModal select[name=status]').val(status);
        });
    });
</script>
@endpush