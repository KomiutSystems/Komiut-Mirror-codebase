@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                <h1 class="m-0"><i class='fas fa-cog'></i> Mpesa Payment <b>Settings</b></h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Payment Settings')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#userModal"><i
                        class='fas fa-plus'></i> Add Credentials</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Mpesa Payment Settings</li>
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
                        <form id='search-form' class='row mb-2'>
                            <div class='col-sm-6'>
                                <label>Search Name</label>
                                <input name='search' class='form-control' placeholder="Search"/>
                            </div>
                            <div class='col-sm-6'>
                                <label>Search Sacco</label>
                                <select name='sacco' id='search-sacco' class='form-control'></select>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class='table w-100'>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Sacco</th>
                                        <th>Short Code</th>
                                        <th>Consumer Key</th>
                                        <th>Consumer Secret</th>
                                        <th>Pass Key</th>
                                        <th>Mode</th>
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
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span> Credentials</h5>
                <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="{{ url('settings/mpesa/add') }}" class="row">
                    @csrf
                    <input type='hidden' name='id' value='0'>
                    <div class='col-sm-6 form-group'>
                        <label>Sacco Name</label>
                        <select name="sacco" class='form-control' id='sacco'>
                        </select>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Business Short Code/Paybill</label>
                        <input type='text' name="business_short_code" class='form-control' placeholder="Business Short Code" required>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Consumer Key</label>
                        <input type='text' name="consumer_key" class='form-control' placeholder="Consumer key" required>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Consumer Secret</label>
                        <input type='text' name="consumer_secret" class='form-control' placeholder="Consumer Secret" required>
                    </div>
                    <div class='col-sm-12 form-group'>
                        <label>Pass Key</label>
                        <input type='text' name="pass_key" class='form-control' placeholder="Pass key" required>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Payment Mode</label>
                        <select name='payment_mode' class='form-control'>
                            <option value="CustomerBuyGoodsOnline">TILL/BUY GOODS</option>
                            <option value="CustomerPayBillOnline">PAYBILL</option>
                        </select>
                    </div>
                    <div class='col-sm-6 form-group'>
                        <label>Status</label>
                        <select name='status' class='form-control'>
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
    $(document).ready(function () {
        
        var sacco_id = "{{ $sacco != null?$sacco->id:0 }}";
        var sacco = "{{ $sacco != null?$sacco->name:0 }}";
        $('#sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                dropdownParent: $('#userModal'),
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

            $('#search-sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#userModal'),
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

        var table = $('.table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ url('settings/datatable/mpesa') }}",
                data: function(d){
                    d.search = $('#search-form input[name=search]').val();
                    d.sacco = $('#search-form select[name=sacco]').val();
                }
            },
            dom: 'lBtrip', //'lfBtrip'
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false,searchable: false },
                { data: 'sacco.name', name: 'sacco.name', defaultContent: 'N/A'},
                { data: 'business_short_code', name: 'business_short_code',},
                { data: 'consumer_key', name: 'consumer_key',},
                { data: 'consumer_secret', name: 'consumer_secret',},
                { data: 'pass_key', name: 'pass_key',},
                { data: 'payment_mode', name: 'payment_mode',},{
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
                { data: 'created_at', name: 'created_at' },
                { data: 'action', name: 'action', orderable: false,searchable: false},
            ]
        });
        var timer = null;
        $('#search-sacco').change(function(){
            table.draw();
        });

        $('#search-form input[name=search]').keyup(function(){
            clearTimeout(timer);
            timer = setTimeout(function(){
                table.draw();
            }, 1000)
        });
        $('.btn-launch-modal').click(function(){
            $('#userModal .modal-title span').text("New ");
            $('#userModal input[name=id]').val(0);
            $('#sacco').val(null).trigger('change');
            $('#userModal input[name=consumer_key]').val("");
            $('#userModal input[name=secret_key]').val("");
            $('#userModal input[name=pass_key]').val("");
            $('#userModal input[name=business_short_code]').val("");
            $('#userModal select[name=status]').val(1);
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
                url: '{{ url("settings/mpesa/add") }}',
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
                    if (data.errors.sacco) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.sacco + "<br>");
                    }
                    if (data.errors.business_short_code) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.business_short_code + "<br>");
                    }
                    
                    if (data.errors.consumer_key) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.consumer_key + "<br>");
                    }
                    
                    if (data.errors.consumer_secret) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.consumer_secret + "<br>");
                    }
                    if (data.errors.pass_key) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.pass_key + "<br>");
                    }
                    
                    if (data.errors.status) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                    }
                    if (data.errors.payment_mode) {
                        $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.payment_mode + "<br>");
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
            $('#userModal .modal-title span').text("Edit ");
            var row = $(this).closest('tr');
            var id = row.find('.id').text();
            var sacco = row.find('.sacco').text();
            var sacco_id = row.find('.sacco_id').text();
            var business_short_code = row.find('.business_short_code').text();
            var consumer_key = row.find('.consumer_key').text();
            var consumer_secret = row.find('.consumer_secret').text();
            var pass_key = row.find('.pass_key').text();
            var payment_mode = row.find('.payment_mode').text();
            var status = row.find('.status').text();

            $('#userModal input[name=id]').val(id);
            if(sacco_id > 0){
                var data = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#sacco').append(newOption).trigger('change');
            }
            $('#userModal input[name=business_short_code]').val(business_short_code);
            $('#userModal input[name=consumer_key]').val(consumer_key);
            $('#userModal input[name=consumer_secret]').val(consumer_secret);
            $('#userModal input[name=pass_key]').val(pass_key);
            $('#userModal select[name=payment_mode]').val(payment_mode); 
            $('#userModal select[name=status]').val(status); 
        });
    });
</script>
@endpush