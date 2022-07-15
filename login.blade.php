@extends('layouts.app')

@include('includes.header')

@section('content') 
    <!-- freeLogin new -->
    <div class="container py-3">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header text-center">{{ __('Free Login New') }}</div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <label>Wanna try a free version of ScheduleManager before buying?</label>
                        </div>
                        <div class="col-md-12">
                            <label>Then click here: </label>

                            <!-- Button trigger modal -->
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success rounded-pill text-uppercase btn-lg btn-block"
                                    data-bs-toggle="modal" data-bs-target="#exampleModal" id="btn-free-account">
                                    Create a free account
                                </button>
                            </div>

                            <!-- Modal -->
                            <form method="POST" action="{{ route('freeLogin') }}" enctype="multipart/form-data">
                                @csrf
                                {{-- <div id="company-name" class="free-account"> --}}
                                <div class="modal fade col-8" id="exampleModal" tabindex="-1"
                                    aria-labelledby="exampleModalLabel" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="exampleModalLabel">Free Login</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                    aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="col-md-12 ">
                                                    <div class="form-group">
                                                        <label>Company Name: (optional)</label>
                                                    </div>
                                                    <div class="col-12">
                                                        <input class="col-12" type="text" name="name">
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Company Email: (required)</label>
                                                    </div>
                                                    <div class="col-12">
                                                        <input class="col-12" type="text" name="mail">
                                                    </div>
                                                    <div class="form-group row g-0">
                                                        <label for="project-upload-schedule">Upload a schedule:
                                                            (required)</label>
                                                        <input type="file" class="form-control"
                                                            id="project-upload-schedule" name="schedule"
                                                            aria-describedby="schedule-help" accept=".mpp,.xml"
                                                            placeholder="Choose file...">
                                                        <small id="schedule-help" class="form-text text-muted">We
                                                            support the
                                                            following
                                                            formats: <code>.xml</code> and <code>.mpp</code></small>
                                                        <p id="invalid-file"></p>
                                                    </div>
                                                    <div class="mb-2">
                                                        <input type="checkbox" id="terms" name="terms">
                                                        <label for="terms"> I agree to the <a
                                                                href="https://www.termsfeed.com/live/1da5dd70-780a-4d8c-ad76-ce63c28458c4">
                                                                terms & coditions</label>
                                                    </div>

                                                    <div class="form-group">
                                                        <div class="col-12">
                                                            <div class="d-grid gap-2 input-group-append">
                                                                <button type="submit"
                                                                    class="btn btn-success rounded-pill text-uppercase btn-lg btn-block col-12">
                                                                    Upload
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>    
@endsection
