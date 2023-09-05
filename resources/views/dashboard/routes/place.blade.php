@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-map-marker-alt'></i> Place</h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ url('routes/places') }}">Places</a></li>
                        <li class="breadcrumb-item active">View</li>
                    </ol>
                </div><!-- /.col -->
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12 mb-3">
                    <div class="col-sm-12 m-2">
                        <div class="card card-primary card-outline">
                            <div class="card-body box-profile">
                                <div class='card-header row d-flex align-items-center'>
                                    <div class='col-sm-8'>
                                        <h5 class="m-0"><b>{{$place->name}}</b> ({{ $place->county_name }})</h5>
                                    </div>
                                    <div class='col-sm-4 text-right'>
                                        <button class='btn btn-primary btn-sm btn-launch-modal' data-toggle="modal" data-target='#placeModal'><i class='fas fa-paper-plane'></i> Update</button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="map-responsive" id="map">
                                        <iframe width="600" height="450" frameborder="0" style="border:0" allowfullscreen>
                                        </iframe>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                </div>
            </div>
        </div>
    </section>

    <div class="modal fade" id="placeModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
         aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-map-marker-alt'></i> <span>Update</span> Place</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('routes/place/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='{{ $place->id }}'>

                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' placeholder="Name" name="name" id='address-input' class='form-control' 
                                   value='{{ $place->name }}' readonly/>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>County Name</label>
                            <input type='text' placeholder="County Name" name="county_name" class='form-control' 
                                   required id='county_name' value='{{ $place->county_name }}' readonly/>
                        </div>
                        <div class="col-sm-6 form-group">
                            <label>Latitude</label>
                            <input id='latitude' name="latitude" class='form-control' placeholder='Latitude' value="{{$place->latitude }}" readonly/>
                        </div>
                        <div class="col-sm-6 form-group">
                            <label>Longitude</label>
                            <input id='longitude' name="longitude" class='form-control' placeholder="Longitude" value="{{$place->longitude }}" readonly>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Status</label>
                            <select name="status" class='form-control'>
                                <option value='1' {{ $place->status?'selected':'' }}>Active</option>
                                <option value='0' {{ !$place->status?'selected':'' }}>Inactive</option>
                            </select>
                        </div>
                        <div class='alert feedback border d-none'>
                            <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                        </div>
                    </form>
                </div>

                <div class="modal-footer pt-2 pb-2">
                    <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btnSave">Save changes</button>
                </div>
            </div>
        </div>
    </div>


@endsection
@push('js')
    <!--   Optional JS   -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAFrY5fH-gBUGMk6zfFnmk7aHZp-Dzzdzo&libraries=places&region=KE"></script>
    
    <script>
        $(document).ready(function () {
            initMap();
            function initMap() {
                const myLatlng = { lat: parseFloat("{{ $place->latitude != null?$place->latitude:'-1.2064151' }}"), lng: parseFloat("{{  $place->longitude != null?$place->longitude:'36.913794' }}") };
                const map = new google.maps.Map(document.getElementById("map"), {
                    zoom: 10,
                    center: myLatlng,
                });
                // Create the initial InfoWindow.
                let infoWindow = new google.maps.InfoWindow({
                    content: "{{ $place->name }}",
                    position: myLatlng,
                });

                infoWindow.open(map);
                // Configure the click listener.
                map.addListener("click", (mapsMouseEvent) => {
                    // Close the current InfoWindow.
                    infoWindow.close();
                    // Create a new InfoWindow.
                    infoWindow = new google.maps.InfoWindow({
                        position: mapsMouseEvent.latLng,
                    });
                    document.getElementById("latitude").value = mapsMouseEvent.latLng.lat();
                    document.getElementById("longitude").value = mapsMouseEvent.latLng.lng();
                    infoWindow.setContent(
                        "{{ $place->name }}",
                        //JSON.stringify(mapsMouseEvent.latLng.lat.toJSON(), null, 2),
                    );
                    infoWindow.open(map);
                });
            }
            
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
        });


    </script>
@endpush
