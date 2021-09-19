use crate::server::settings::Settings;
use crate::Result;
use tokio::io::{stdin, BufReader, AsyncBufReadExt, AsyncWriteExt, AsyncReadExt};
use tokio::sync::mpsc::channel;
use tokio::task::JoinHandle;
use tokio::net::{TcpListener, TcpStream};
use std::collections::HashMap;
use std::net::SocketAddr;
use std::sync::Arc;
use tokio::sync::Mutex;
use std::borrow::BorrowMut;
use tokio::prelude::*;

mod settings;
mod events;

pub async fn new() -> Result<Server> {
    let mut path = std::env::current_dir()?;
    path.push("config.yml");
    let settings = settings::load(&path)?;
    Ok(Server {
        settings,
        running: false,
        tick: 0,
        threads: vec![],
        clients: Arc::new(Default::default())
    })
}

enum Receivable {
    Command(String),
    Event(events::Event),
}


pub struct Server {
    pub settings: Settings,
    threads: Vec<JoinHandle<()>>,
    running: bool,
    tick: u128,
    clients: Arc<Mutex<HashMap<SocketAddr, tokio::io::WriteHalf<TcpStream>>>>
}

impl Server {
    pub async fn start(&mut self) -> Result<()>  {
        self.running = true;
        let (mut tx, mut tr) = channel::<Receivable>(2048);
        self.threads.push(tokio::spawn(async move {
            loop {
                let mut inp = String::new();
                BufReader::new(stdin()).read_line(&mut inp).await.unwrap();
                tx.send(Receivable::Command(inp.trim().to_string())).await;
            }
        }));
        let mut listener = TcpListener::bind(self.settings.bind_address.clone() + ":" + &self.settings.server_port.to_string()).await?;
        let clients_recv = Arc::clone(&self.clients);
        self.threads.push(tokio::spawn(async move {
            loop {
                let (mut sock, addr) = listener.accept().await.unwrap();
                let (mut reader, mut writer) = tokio::io::split(sock);
                let mut tmp = clients_recv.lock().await;
                tmp.insert(addr, writer);
                tokio::spawn(async move {
                    let mut buf = vec![0; 1024];
                    loop {
                        let n = reader
                            .read(&mut buf)
                            .await
                            .expect("failed to read data from socket");

                        if n == 0 {
                            return;
                        }
                        println!("{:?}", buf);
                    }
                });
            }
        }));
        while self.running {
            self.tick += 1;
            let received = tr.recv().await.unwrap();
            match received {
                Receivable::Command(command) => {
                    match &*command {
                        "stop" => self.stop(),
                        _ => println!("Unknown command.")
                    }
                },
                Receivable::Event(event) => {

                }
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