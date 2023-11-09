@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-map-marker-alt'></i> Places</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Places')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#placeModal"><i
                        class='fas fa-plus'></i> Add Place</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Places</li>
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
                            <div class="card-body">
                                <form class='search-form row d-flex align-items-end' id='search-form'>
                                    <div class="col-sm-4">
                                        <label>Search Name</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    
                                    <!--
                                    <div class="col-sm-3">
                                        <label>From Date</label>
                                        <input type="date" class="form-control mb-1" id="from_date" name="from_date">
                                    </div>
                                    <div class="col-sm-3">
                                        <label>To Date</label>
                                        <input type="date" class="form-control mb-1" id="to_date" name="to_date">
                                    </div>
                                -->
                                    <div class="col-sm-4">
                                        <label>Status</label>
                                        <select name="status" class="form-control mb-1">
                                            <option value="">All</option>
                                            <option value='1'>Active</option>
                                            <option value='0'>In-Active</option>
                                        </select>
                                    </div>
                                    <div class='col-sm-4 text-right'>
                                        <button type='submit' id='search-btn' class='btn btn-primary w-100 m-1'>Search
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>County Name</th>
                                        <th>Status</th>
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
    <div class="modal fade" id="placeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-map-marker-alt'></i> <span>New </span>
                        Place</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('routes/place/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <input type='hidden' placeholder="Longitude" name="longitude" class='form-control' autofocus
                               required id='address-longitude'/>
                        <input type='hidden' placeholder="Latitude" name="latitude" class='form-control' autofocus
                               required id='address-latitude'/>

                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' placeholder="Name" name="name" id='address-input' class='form-control' autofocus
                                   required/>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>County Name</label>
                            <input type='text' placeholder="County Name" name="county_name" class='form-control' autofocus
                                   required id='county_name'/>
                        </div>
                        <div class='col-sm-12 form-group'>
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
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAFrY5fH-gBUGMk6zfFnmk7aHZp-Dzzdzo&libraries=places&region=KE"></script>
    <script>

        $(document).ready(function () {
            from();
            // Autocomplete Options
            function from(){
                var defaultBounds = new google.maps.LatLngBounds();
                var options = {
                        types: ['(cities)'],
                        componentRestrictions: {country: "ke"},
                        bounds: defaultBounds
                    };
                var input = document.getElementById('address-input');
            
                // Make Autocomplete instance
                var autocomplete = new google.maps.places.Autocomplete(input, options);
            
                // Listener for whenever input value changes            
                autocomplete.addListener('place_changed', function() {
                    // Get place info
                    var place = autocomplete.getPlace();
                    var county = place.address_components[1].long_name;
                    //window.alert(place.geometry.location.lat()+", "+place.geometry.location.lng());
                    var latitude = document.getElementById('address-latitude');
                    var county_name = document.getElementById('county_name');
                    var longitude = document.getElementById('address-longitude');
                    latitude.value = place.geometry.location.lat();
                    longitude.value = place.geometry.location.lng();
                    county_name.value = county;
                });
            }

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('routes/datatable/places') }}",
                    data: function (d) {
                        d.search = $('input[name=search]').val();
                        d.status = $('select[name=status]').val();
                        d.is_datable = true;
                    }
                },
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: 'places',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: 'places',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: 'places',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [ [20, 100, 250, 500, 1000], [20,100, 250, 500, 1000] ],
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'name', name: 'name'},
                    {data: 'county_name', name: 'county_name'},
                    {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge badge-primary">Active</span>';
                                default:
                                    return '<span class="badge badge-secondary">Inactive</span>';
                            }
                        }
                    },
                    {data: 'created_at', name: 'created_at'},
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });

            $('#search-form').on('submit', function (e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function () {
                $('#placeModal .modal-title span').text("New ");
                $('#placeModal input[name=id]').val(0);
                $('#placeModal input[name=name]').val("");
                $('#placeModal input[name=slogan]').val("");
                $('#placeModal input[name=phone]').val("");
                $('#placeModal input[name=status]').val("");
            });
            $('#placeModal .btnSave').click(function () {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#placeModal .feedback').removeClass('d-none');
                $('#placeModal .feedback').removeClass('alert-danger');
                $('#placeModal .feedback').removeClass('alert-success');
                $('#placeModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#placeModal form').serialize();
                $.ajax({
                    url: '{{ url("routes/place/add") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('#placeModal .feedback').addClass('alert-success');
                    $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#placeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('#placeModal .feedback').addClass('alert-danger');
                    $('#placeModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                        }
                        if (data.errors.longitude) {
                            $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.longitude + "<br>");
                        }
                        if (data.errors.latitude) {
                            $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.latitude + "<br>");
                        }
                        if (data.errors.county_name) {
                            $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.county_name + "<br>");
                        }
                        if (data.errors.status) {
                            $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                        }
                    } else if (data.error) {
                        $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('#placeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function () {
                $('#placeModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var county_name = row.find('.county_name').text();
                var longitude = row.find('.longitude').text();
                var latitude = row.find('.latitude').text();
                var status = row.find('.status').text();

                $('#placeModal input[name=id]').val(id);
                $('#placeModal input[name=name]').val(name);
                $('#placeModal input[name=county_name]').val(county_name);
                $('#placeModal input[name=longitude]').val(longitude);
                $('#placeModal input[name=latitude]').val(latitude);
                $('#placeModal input[name=status]').val(status);
            });

        });
    </script>
@endpush
