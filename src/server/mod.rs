use crate::server::settings::Settings;
use crate::Result;
use libflate::deflate::Decoder;
use libflate::deflate::{EncodeOptions, Encoder};
use lumine_protocol::{CanIo, Little};
use std::collections::HashMap;
use std::io::{ErrorKind, Read, Write};
use std::net::SocketAddr;
use std::sync::Arc;
use tokio::io::{stdin, AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::mpsc::channel;
use tokio::sync::Mutex;
use tokio::task::JoinHandle;

pub mod events;
use events::*;
mod settings;

//this was to long to fucking have every damn time i needed to send shit so here we are.
macro_rules! send {
    ($pk:ident, $buf:ident, $sock:ident) => {{
        let mut enc = Encoder::with_options(Vec::new(), EncodeOptions::new().fixed_huffman_codes());
        let mut tmp = Vec::new();
        $pk.write(&mut tmp);
        enc.write_all(&tmp)?;
        let encoded = enc.finish().into_result()?;
        Little(encoded.len() as i32).write(&mut $buf);
        $buf.extend(encoded);
        $sock.write_all($buf.as_slice())
    }};
}

pub async fn new() -> Result<Server> {
    let mut path = std::env::current_dir()?;
    path.push("config.yml");
    let settings = settings::load(&path)?;
    Ok(Server {
        settings,
        running: false,
        tick: 0,
        threads: vec![],
        clients: Arc::new(Default::default()),
    })
}

enum Receivable {
    Command(String),
    Event(SocketAddr, Event),
}

pub struct Server {
    pub settings: Settings,
    threads: Vec<JoinHandle<()>>,
    running: bool,
    tick: u128,
    clients: Arc<Mutex<HashMap<SocketAddr, tokio::io::WriteHalf<TcpStream>>>>,
}

impl Server {
    pub async fn start(&mut self) -> Result<()> {
        self.running = true;
        let (tx, mut tr) = channel::<Receivable>(2048);
        let mut ctx = tx.clone();
        self.threads.push(tokio::spawn(async move {
            loop {
                let mut inp = String::new();
                BufReader::new(stdin()).read_line(&mut inp).await.unwrap();
                ctx.send(Receivable::Command(inp.trim().to_string())).await;
            }
        }));
        let mut listener = TcpListener::bind(
            self.settings.bind_address.clone() + ":" + &self.settings.server_port.to_string(),
        )
        .await?;
        let clients_recv = Arc::clone(&self.clients);
        let ttx = Arc::new(Mutex::new(tx));
        self.threads.push(tokio::spawn(async move {
            loop {
                let (sock, addr) = listener.accept().await.expect("Socket server closed.");
                let (mut reader, writer) = tokio::io::split(sock);
                let mut tmp = clients_recv.lock().await;
                tmp.insert(addr, writer);
                let ttx = Arc::clone(&ttx);
                tokio::spawn(async move {
                    let mut buf = vec![0; 1024];
                    let ttx = Arc::clone(&ttx);
                    loop {
                        let n = reader
                            .read(&mut buf)
                            .await
                            .expect("failed to read data from socket");

                        if n == 0 {
                            return;
                        }
                        let mut offset = 0;
                        let len = Little::<i32>::read(buf.as_slice(), &mut offset)
                            .expect("Failed to react packet length!")
                            .inner() as usize;
                        let mut decoder = Decoder::new(&buf.as_slice()[offset..=(offset + len)]);
                        let mut decoded = Vec::new();
                        decoder
                            .read_to_end(&mut decoded)
                            .expect("Invalid deflate (ZLIB_DEFLATE) sent!");
                        ttx.lock()
                            .await
                            .send(Receivable::Event(
                                addr,
                                events::Event::read(decoded.as_slice(), &mut 0)
                                    .expect("Invalid Packet Received!"),
                            ))
                            .await;
                    }
                });
            }
        }));
        while self.running {
            self.tick += 1;
            let received = tr.recv().await.unwrap();
            match received {
                Receivable::Command(command) => match &*command {
                    "stop" => self.stop(),
                    _ => println!("Unknown command."),
                },
                Receivable::Event(from, event) => match event {
                    Event::Heartbeat(_) => {
                        let pk = Event::Heartbeat(Heartbeat {});
                        self.send(pk, from).await?;
                    }
                    _ => {
                        println!("Unknown packet received!")
                    }
                },
            }
        }
        Ok(())
    }

    pub async fn send(&self, pk: Event, addr: SocketAddr) -> std::io::Result<()> {
        let mut buf = Vec::new();
        match self.clients.lock().await.get_mut(&addr) {
            Some(sock) => {
                send!(pk, buf, sock).await?;
            }
            None => {
                return Err(std::io::Error::new(
                    ErrorKind::AddrNotAvailable,
                    "Received a packet from a unconnected Addr!",
                ))
            }
        }
        Ok(())
    }

    pub fn stop(&mut self) {
        println!("Shutting down the Lumine server...");
        self.running = false;
        std::process::exit(0);
    }
}
