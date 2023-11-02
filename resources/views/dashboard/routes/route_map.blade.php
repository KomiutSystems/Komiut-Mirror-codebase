@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-map-marker-alt'></i> Route Map</h1>
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
                                    <div class='col-sm-12'>
                                        <h5 class="m-0"><b>{{ $route->name }}</b> ({{ $route->from->name }} -
                                            {{ $route->to->name }})</h5>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="map-responsive" id="map">
                                        <iframe width="600" height="450" frameborder="0" style="border:0"
                                            allowfullscreen>
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
@endsection
@push('js')
    <!--   Optional JS   -->
    <script
        src="https://maps.googleapis.com/maps/api/js?key=***REMOVED***&libraries=places&region=KE">
    </script>
    <script>
        let stages = [];
    </script>
    @foreach ($route->route_stages as $stage)
        <script>
            stages.push({
                name: "{{ $stage->place->name }}",
                longitude: "{{ $stage->longitude != null ? $stage->longitude : $stage->place->longitude }}",
                latitude: "{{ $stage->latitude != null ? $stage->latitude : $stage->place->latitude }}"
            });
        </script>
    @endforeach
    <script>
        $(document).ready(function() {
            initMap();

            function initMap() {
                const myLatlng = {
                    lat: parseFloat(
                        "{{ $route->from->latitude != null ? $route->from->latitude : '-1.2064151' }}"),
                    lng: parseFloat(
                        "{{ $route->from->longitude != null ? $route->from->longitude : '36.913794' }}")
                };
                const map = new google.maps.Map(document.getElementById("map"), {
                    zoom: 10,
                    center: myLatlng,
                });
                // Create the initial InfoWindow.
                let infoWindow = new google.maps.InfoWindow({
                    content: "{{ $route->name . ' (' . $route->from->name . ' - ' . $route->to->name . ')' }}",
                    position: myLatlng,
                });
                const flightPlanCoordinates = [/*{
                        lat: parseFloat("{{ $route->from->latitude }}"),
                        lng: parseFloat("{{ $route->from->longitude }}")
                    },
                    
                                        {
                                            lat: parseFloat("{{ $route->to->latitude }}"),
                                            lng: parseFloat("{{ $route->to->longitude }}")
                                        },*/

                ];

                for (var i = 0; i < stages.length; i++) {
                    flightPlanCoordinates.push({lat: parseFloat(""+stages[i].latitude+""), lng: parseFloat(""+stages[i].longitude+"")})
                    console.log(stages[i]);
                    var myInfoWindow = new google.maps.InfoWindow({
                    content: stages[i].name,
                    position: {lat: parseFloat(""+stages[i].latitude+""), lng: parseFloat(""+stages[i].longitude+"")},
                });
                myInfoWindow.open(map)
                }

                const flightPath = new google.maps.Polyline({
                    path: flightPlanCoordinates,
                    geodesic: true,
                    strokeColor: "#FF0000",
                    strokeOpacity: 1.0,
                    strokeWeight: 2,
                });

                flightPath.setMap(map);

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
                        "{{ $route->name . ' (' . $route->from->name . ' - ' . $route->to->name . ')' }}",
                        //JSON.stringify(mapsMouseEvent.latLng.lat.toJSON(), null, 2),
                    );
                    infoWindow.open(map);
                });
            }
        });
    </script>
@endpush
