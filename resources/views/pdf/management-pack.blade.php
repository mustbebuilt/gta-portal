@extends('pdf.layout')
@section('title','Operational management pack')
@section('content')
<style>p{margin:5px 0}.record{margin:6px 0;padding:7px 0}h2{margin-top:14px}</style>
<h1>{{ $pack->title }}</h1><p>Saved by {{ $pack->snapshot['captured_actor']['name']??'Account '.$pack->captured_by }} · {{ $pack->created_at }}</p><p>Fixed snapshot {{ $pack->snapshot_hash }}</p>
@include('management.content',['snapshot'=>$pack->snapshot])
@endsection
