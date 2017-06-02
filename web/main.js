function outputTable(simple) {
  var table = '';
  if ("undefined" === typeof simple) {
    return '';
  }

  if ("undefined" !== typeof simple.header) {
    $.each(simple.header, function(k, v) {
      table += '<div>' + v + '</div>';
    });
    delete simple.header;
  }

  $.each(simple, function(k, v) {
    var id = guidGenerator();
    table += '<h4><a role="button" data-toggle="collapse" href="#'+ id + '" aria-expanded="false" aria-controls="'+ id + '">' + k + '</a></h4>';
    table += '<div class="collapse in" id="' + id + '">';
    table += '<table class="table"><thead><tr><th>Tag</th><th>Score</th></tr></thead><tbody>';

    $.each(v, function(k2, v2) {
      var link = v2.link ? v2.link : '/meta?q=' + v2.tag;
      var score = v2.score ? v2.score : '';

      table += '<tr>';
      table += '<td><a href="' + link + '" target="_blank">' + v2.tag + '</a> ' + (v2.extra ? v2.extra : '') + '</td>';
      table += '<td>' + score + '</td>';
      table += '</tr>';
    });
    table += '</tbody></table>';
    table += '</div>';
  });
  return table;
}

function guidGenerator() {
  var S4 = function() {
    return (((1+Math.random())*0x10000)|0).toString(16).substring(1);
  };
  return (S4()+S4()+"-"+S4()+"-"+S4()+"-"+S4()+"-"+S4()+S4()+S4());
}

function standardFetch(service, image_id, reset) {
  reset = reset ? '?reset=1' : '';
  $( "#" + service + " .tags" ).html('<span class="glyphicon glyphicon-refresh spinning"></span> Loading');
  $("#" + service + " .json-container").hide();
  $.getJSON( "/api/" + service + "/" + image_id + reset, function( data ) {
    var table = outputTable(data.simple);
    $( "#" + service + " .tags" ).html(table);
    delete data.simple;
    var str = JSON.stringify(data, null, 2);
    $( "#" + service + " .json" ).html( str );
    $("#" + service + " .json").each(function(i, e) {hljs.highlightBlock(e)});
    $("#" + service + " .json-container").show();
});
}

function fetchAll(id, reset) {
  standardFetch('google', id, reset);
  standardFetch('clarifai', id, reset);
  standardFetch('imagga', id, reset);
  standardFetch('microsoft', id, reset);
  standardFetch('cloudsite', id, reset);
}
